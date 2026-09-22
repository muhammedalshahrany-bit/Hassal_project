<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Deal;
use App\Models\Dispute;
use App\Services\DealStateMachineService;
use App\Services\DoubleEntryLedgerService;
use App\Services\TenantContext;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DisputeController extends Controller
{
    public function __construct(
        protected DealStateMachineService $stateMachine,
        protected DoubleEntryLedgerService $ledgerService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();
        $user = $request->user();

        $validated = $request->validate([
            'status' => ['nullable', 'in:' . implode(',', Dispute::STATUSES)],
        ]);

        $query = Dispute::with(['deal.buyerOrganization', 'deal.sellerOrganization', 'initiatorOrganization', 'initiatorUser']);

        if (!$user->hasRole('SuperAdmin')) {
            if (!$orgId) {
                return ApiResponse::error('Active organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
            }

            $query->whereHas('deal', function ($d) use ($orgId) {
                $d->where('buyer_organization_id', $orgId)->orWhere('seller_organization_id', $orgId);
            });
        }

        // A filter the query ignores would still be reported as a count, so an
        // unknown value is refused rather than read as "no disputes".
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $disputes = $query->orderBy('created_at', 'desc')->paginate(20);
        return ApiResponse::paginated($disputes, 'Disputes retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();
        if (!$orgId) {
            return ApiResponse::error('Active organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'deal_id' => ['required', 'exists:deals,id'],
            'reason' => ['required', 'string'],
            'description' => ['required', 'string'],
            'claimed_amount' => ['required', 'numeric', 'min:0.01'],
            'evidence_urls' => ['nullable', 'array'],
        ]);

        $deal = Deal::where(function ($q) use ($orgId) {
            $q->where('buyer_organization_id', $orgId)->orWhere('seller_organization_id', $orgId);
        })->findOrFail($validated['deal_id']);

        $dispute = DB::transaction(function () use ($validated, $deal, $orgId, $request) {
            $newDispute = Dispute::create([
                'dispute_number' => 'DSP-' . date('ymd') . '-' . strtoupper(Str::random(4)),
                'deal_id' => $deal->id,
                'initiator_organization_id' => $orgId,
                'initiator_user_id' => $request->user()->id,
                'reason' => $validated['reason'],
                'description' => $validated['description'],
                'claimed_amount' => $validated['claimed_amount'],
                'evidence_urls' => $validated['evidence_urls'] ?? null,
                'status' => Dispute::STATUS_OPEN,
            ]);

            // Transition deal to DISPUTED status
            if ($deal->status !== Deal::STATUS_DISPUTED) {
                $this->stateMachine->transition($deal, Deal::STATUS_DISPUTED, $request->user(), 'Dispute opened: ' . $validated['reason']);
            }

            return $newDispute;
        });

        return ApiResponse::success($dispute->load('deal'), 'Dispute filed successfully. The deal is now marked as disputed.', Response::HTTP_CREATED);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->hasRole('SuperAdmin')) {
            return ApiResponse::error('Only platform administrators can resolve disputes', 'FORBIDDEN', Response::HTTP_FORBIDDEN);
        }

        $dispute = Dispute::with('deal')->findOrFail($id);

        $validated = $request->validate([
            'decision' => ['required', 'in:RESOLVED_REFUND,RESOLVED_RELEASE,RESOLVED_PARTIAL,REJECTED'],
            'refunded_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['required', 'string'],
        ]);

        DB::transaction(function () use ($dispute, $validated, $request) {
            $decision = $validated['decision'];
            $refundAmount = (float)($validated['refunded_amount'] ?? 0);

            $dispute->update([
                'status' => $decision,
                'resolution_decision' => $decision,
                'refunded_amount' => $refundAmount,
                'resolution_notes' => $validated['notes'],
                'resolved_by_user_id' => $request->user()->id,
                'resolved_at' => Carbon::now(),
            ]);

            if ($decision === 'RESOLVED_REFUND') {
                $this->stateMachine->transition($dispute->deal, Deal::STATUS_REFUNDED, $request->user(), 'Dispute resolved with full refund to buyer');
            } elseif ($decision === 'RESOLVED_RELEASE' || $decision === 'REJECTED') {
                $this->stateMachine->transition($dispute->deal, Deal::STATUS_SETTLED, $request->user(), 'Dispute resolved in favor of seller');
            } elseif ($decision === 'RESOLVED_PARTIAL') {
                $this->stateMachine->transition($dispute->deal, Deal::STATUS_RESOLVED, $request->user(), 'Dispute resolved with partial refund');
            }
        });

        return ApiResponse::success($dispute->fresh(['deal', 'resolvedByUser']), 'Dispute resolved successfully');
    }
}
