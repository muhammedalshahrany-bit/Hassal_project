<?php

namespace App\Trading\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Http\ApiResponse;
use App\Core\Exceptions\ApiRefused;
use App\Trading\Models\Dispute;
use App\Trading\Services\DisputeCaseService;
use App\Core\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The complaints desk: the book of cases, filing one, ruling on one.
 *
 * What a dispute is, when it may be filed and what a ruling does to the order
 * under it is `DisputeCaseService`'s business — it is the part that has to keep
 * two records in step, and a controller is the worst place to hold that. This
 * file decides who is asking, which fields they were allowed to send, and what
 * shape the answer takes: the three things only the transport knows.
 */
class DisputeController extends Controller
{
    public function __construct(
        protected DisputeCaseService $cases
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();
        $user = $request->user();

        $validated = $request->validate([
            'status' => ['nullable', 'in:' . implode(',', Dispute::STATUSES)],
        ]);

        $query = Dispute::with(['deal.buyerOrganization', 'deal.sellerOrganization', 'initiatorOrganization', 'initiatorUser']);

        if (!$user->isPlatformOperator()) {
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

        try {
            $dispute = $this->cases->file(
                (int) $validated['deal_id'],
                $validated,
                $orgId,
                $request->user()
            );
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success($dispute->load('deal'), 'Dispute filed successfully. The deal is now marked as disputed.', Response::HTTP_CREATED);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->isPlatformOperator()) {
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

        $user = $request->user();
        $cancels = $this->cases->callsTheOrderOff($validated['status']);

        try {
            $this->cases->rule($dispute, $validated, $user);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $dispute->fresh(['deal', 'resolvedByUser']),
            $cancels
                ? 'Dispute ruled: the order is called off and its quantity is back on the listing.'
                : 'Dispute ruled: the order stands and can be closed once its handover is attested.'
        );
    }
}
