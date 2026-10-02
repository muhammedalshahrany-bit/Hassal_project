<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Deal;
use App\Models\Dispute;
use App\Services\DealStateMachineService;
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
        protected DealStateMachineService $stateMachine
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
            'reason' => ['required', 'string', 'in:' . implode(',', Dispute::REASONS)],
            'description' => ['required', 'string', 'max:5000'],
            'claimed_amount' => ['required', 'numeric', 'min:0.01'],
            'evidence_urls' => ['nullable', 'array'],
            'evidence_urls.*' => ['string', 'max:2048'],
        ]);

        $deal = Deal::where(function ($q) use ($orgId) {
            $q->where('buyer_organization_id', $orgId)->orWhere('seller_organization_id', $orgId);
        })->findOrFail($validated['deal_id']);

        // One open case per order. Without this, the second filing on the same
        // order skipped the transition (the deal was already DISPUTED) and left
        // two live disputes for the platform to rule on separately — which is how
        // one order ends up cancelled by one ruling and released by another.
        // Checked before the state gate below, because an order sitting in
        // DISPUTED is exactly the case this message is for.
        if (Dispute::where('deal_id', $deal->id)->whereIn('status', Dispute::OPEN_STATUSES)->exists()) {
            return ApiResponse::error(
                'This order already has a dispute open. Add to it rather than filing another.',
                'DISPUTE_ALREADY_OPEN',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // A complaint can only attach to an order that is still in play. On a
        // draft there is nothing to complain about yet; on a completed or
        // cancelled order the chain has already closed, and the machine would
        // refuse the transition anyway — better to say so here, where the answer
        // names the order's state, than to let an exception surface as a 500.
        if (!in_array($deal->status, DealStateMachineService::disputableStatuses(), true)) {
            return ApiResponse::error(
                "An order that is {$deal->status} cannot be disputed.",
                'DEAL_NOT_DISPUTABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

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

            // The acting organization is passed because the machine decides who
            // may move a deal by which side of the table they sit on. Left out,
            // every filing from a buyer or a seller would be refused for having
            // no context rather than for being wrong, and the complaint that
            // never reaches `DISPUTED` would leave a live dispute row on an order
            // the platform is not told about.
            $this->stateMachine->transition(
                $deal,
                Deal::STATUS_DISPUTED,
                $request->user(),
                'Dispute opened: ' . $validated['reason'],
                null,
                $orgId
            );

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
            // The ruling is named exactly as the record stores it. There is no
            // second `decision` vocabulary to translate into a status — the last
            // version of this endpoint kept both, and wrote the same string into
            // two columns — and no amount to hand back, because a platform that
            // never held the money has nothing to refund. What it can decide is
            // whether the order stands or is called off.
            'status' => ['required', 'in:' . implode(',', Dispute::RESOLVED_STATUSES)],
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        if (in_array($dispute->status, Dispute::RESOLVED_STATUSES, true)) {
            return ApiResponse::error(
                'This dispute has already been ruled on.',
                'DISPUTE_ALREADY_RESOLVED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $user = $request->user();
        $deal = $dispute->deal;
        $cancels = $validated['status'] === Dispute::STATUS_RESOLVED_CANCELLATION;

        DB::transaction(function () use ($dispute, $validated, $user, $deal, $cancels) {
            $dispute->update([
                'status' => $validated['status'],
                'resolution_notes' => $validated['notes'],
                'resolved_by_user_id' => $user->id,
                'resolved_at' => Carbon::now(),
            ]);

            // `RESOLVED` is the dispute's ending, not the order's: the row says a
            // neutral has ruled, and the goods then either carry on to the
            // buyer's receipt or are called off. A deal that is not sitting in
            // DISPUTED — the complaint was filed, then the order was closed some
            // other way — is left where it is; the ruling is still on the record.
            if ($deal && $deal->status === Deal::STATUS_DISPUTED) {
                $transitioned = $this->stateMachine->transition(
                    $deal,
                    Deal::STATUS_RESOLVED,
                    $user,
                    "Dispute {$dispute->dispute_number} ruled: {$validated['notes']}"
                );

                if ($cancels) {
                    // Two steps, in the machine's own order, so the cancellation
                    // runs the release of stock and the retirement of the
                    // handover code instead of this file doing either by hand.
                    $this->stateMachine->transition(
                        $transitioned,
                        Deal::STATUS_CANCELLED,
                        $user,
                        "Dispute {$dispute->dispute_number}: order called off"
                    );
                }
            }
        });

        return ApiResponse::success(
            $dispute->fresh(['deal', 'resolvedByUser']),
            $cancels
                ? 'Dispute ruled: the order is called off and its quantity is back on the listing.'
                : 'Dispute ruled: the order stands and can be closed once its handover is attested.'
        );
    }
}
