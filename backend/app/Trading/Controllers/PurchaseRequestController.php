<?php

namespace App\Trading\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Http\ApiResponse;
use App\Marketplace\Models\DemandMatch;
use App\Marketplace\Models\Offer;
use App\Core\Exceptions\ApiRefused;
use App\Trading\Models\PurchaseRequest;
use App\Trading\Services\PurchaseRequestService;
use App\Core\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The buyer's ask, reviewed by the seller — the HTTP edge.
 *
 * Who may act is answered here against the resolved tenant: only the seller
 * named on a request accepts, rejects or counters it, and only the buyer who
 * raised it adopts a counter or withdraws the ask. What the answer *does* —
 * the tradability bounds, the reservation a real acceptance runs, the state
 * each reply writes — is `PurchaseRequestService`'s, and its refusals reach
 * the client through `refused()` unchanged.
 */
class PurchaseRequestController extends Controller
{
    public function __construct(
        protected PurchaseRequestService $requests,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = TenantContext::id();

        if (!$organizationId) {
            return ApiResponse::error('Active organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $requests = PurchaseRequest::with(['demand', 'offer.variant.product', 'buyerOrganization', 'sellerOrganization'])
            ->where(function ($query) use ($organizationId) {
                $query->where('buyer_organization_id', $organizationId)
                    ->orWhere('seller_organization_id', $organizationId);
            })
            ->orderByDesc('created_at')
            ->paginate(20);

        return ApiResponse::paginated($requests, 'Purchase requests retrieved successfully');
    }

    public function show(int $id): JsonResponse
    {
        $requestRecord = PurchaseRequest::with(['demand', 'offer', 'buyerOrganization', 'sellerOrganization'])
            ->findOrFail($id);

        // A request names a price and a quantity for a named counterparty.
        // Only the two organizations in it — or the platform — may read it.
        if (!$this->isPartyTo($requestRecord)) {
            return ApiResponse::error('This purchase request belongs to another organization', 'PURCHASE_REQUEST_ACCESS_DENIED', Response::HTTP_FORBIDDEN);
        }

        return ApiResponse::success($requestRecord, 'Purchase request retrieved successfully');
    }

    /**
     * The buyer's ask raised straight from a live offer. Deliberately a
     * request, not a hold — see the service for what that means and why.
     */
    public function storeFromOffer(Request $request): JsonResponse
    {
        $buyerOrgId = TenantContext::id();
        if (!$buyerOrgId) {
            return ApiResponse::error('Active buyer organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'requested_quantity' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:500'],
            'delivery_preference' => ['nullable', 'in:PICKUP,DELIVERY'],
        ]);

        $offer = Offer::with('seller')->findOrFail($validated['offer_id']);

        try {
            $purchaseRequest = $this->requests->createFromOffer($offer, $buyerOrgId, $request->user(), $validated);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $purchaseRequest->load(['offer', 'buyerOrganization', 'sellerOrganization']),
            'Purchase request created. No stock is held until the seller reviews it.',
            Response::HTTP_CREATED
        );
    }

    public function storeFromMatch(Request $request, int $matchId): JsonResponse
    {
        $buyerOrgId = TenantContext::id();
        if (!$buyerOrgId) {
            return ApiResponse::error('Active buyer organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $match = DemandMatch::with(['demand', 'offer'])->findOrFail($matchId);

        if ((int) $match->demand->buyer_organization_id !== (int) $buyerOrgId) {
            return ApiResponse::error('This match belongs to another organization', 'MATCH_ACCESS_DENIED', Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'requested_quantity' => ['nullable', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:500'],
            'delivery_preference' => ['nullable', 'in:PICKUP,DELIVERY'],
        ]);

        try {
            $purchaseRequest = $this->requests->createFromMatch($match, $buyerOrgId, $request->user(), $validated);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $purchaseRequest->load(['demand', 'offer', 'buyerOrganization', 'sellerOrganization']),
            'Purchase request created successfully. It is now awaiting the seller’s review.',
            Response::HTTP_CREATED
        );
    }

    /**
     * Seller acceptance: the moment stock is actually held.
     */
    public function accept(Request $request, int $id): JsonResponse
    {
        $purchaseRequest = PurchaseRequest::with(['offer', 'demand', 'buyerUser'])->findOrFail($id);

        if ((int) $purchaseRequest->seller_organization_id !== TenantContext::id()) {
            return ApiResponse::error('Only the seller organization may accept this request', 'SELLER_ACCEPT_DENIED', Response::HTTP_FORBIDDEN);
        }

        try {
            // State first, then the work: the ask's shape is read whole here,
            // so the guard sits beside the call rather than before a form.
            $this->requests->assertAcceptable($purchaseRequest);

            $this->requests->accept(
                $purchaseRequest,
                $request->user(),
                $purchaseRequest->buyerUser,
                $request->input('notes')
            );
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $purchaseRequest->load(['reservation', 'offer']),
            'Purchase request accepted and the requested quantity is held for the buyer.',
            Response::HTTP_OK
        );
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $purchaseRequest = PurchaseRequest::with(['offer', 'demand'])->findOrFail($id);

        if ((int) $purchaseRequest->seller_organization_id !== TenantContext::id()) {
            return ApiResponse::error('Only the seller organization may reject this request', 'SELLER_REJECT_DENIED', Response::HTTP_FORBIDDEN);
        }

        // The state gate is asked *before* the form is read, so a closed ask is
        // refused for being closed and not for the missing `reason` behind it.
        try {
            $this->requests->assertRejectable($purchaseRequest);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->requests->reject($purchaseRequest, $request->user(), $validated['reason']);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success($purchaseRequest, 'Purchase request rejected');
    }

    /**
     * Negotiation records a counter-offer; it never edits the buyer's ask.
     */
    public function propose(Request $request, int $id): JsonResponse
    {
        $purchaseRequest = PurchaseRequest::with(['offer', 'demand'])->findOrFail($id);

        if ((int) $purchaseRequest->seller_organization_id !== TenantContext::id()) {
            return ApiResponse::error('Only the seller organization may propose changes', 'SELLER_PROPOSE_DENIED', Response::HTTP_FORBIDDEN);
        }

        try {
            $this->requests->assertProposable($purchaseRequest);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        $validated = $request->validate([
            'proposed_terms' => ['required', 'array'],
            'proposed_terms.quantity' => ['nullable', 'numeric', 'min:0.01'],
            'proposed_terms.unit_price' => ['nullable', 'numeric', 'min:0.0001'],
            'proposed_terms.currency_code' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->requests->propose($purchaseRequest, $request->user(), $validated);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success($purchaseRequest, 'Seller proposal submitted');
    }

    /**
     * Buyer agreement to a counter-offer: rewrites the ask onto the seller's
     * terms and sends it back; holds no stock and forms no deal.
     */
    public function acceptProposal(Request $request, int $id): JsonResponse
    {
        $purchaseRequest = PurchaseRequest::findOrFail($id);

        if ((int) $purchaseRequest->buyer_organization_id !== TenantContext::id()) {
            return ApiResponse::error('Only the buyer organization may answer this proposal', 'BUYER_PROPOSAL_DENIED', Response::HTTP_FORBIDDEN);
        }

        try {
            $this->requests->assertHasOpenProposal($purchaseRequest);
            $this->requests->adoptProposal($purchaseRequest);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $purchaseRequest,
            'Proposal adopted. The request is waiting for the seller to confirm it.'
        );
    }

    /**
     * The buyer withdraws an ask that has not been answered yet.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $purchaseRequest = PurchaseRequest::findOrFail($id);

        if ((int) $purchaseRequest->buyer_organization_id !== TenantContext::id()) {
            return ApiResponse::error('Only the buyer organization may withdraw this request', 'BUYER_CANCEL_DENIED', Response::HTTP_FORBIDDEN);
        }

        try {
            $this->requests->assertWithdrawable($purchaseRequest);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->requests->cancel($purchaseRequest, $validated['reason'] ?? null);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success($purchaseRequest, 'Purchase request withdrawn');
    }

    /**
     * Is the acting tenant one of the two organizations named on this request?
     */
    protected function isPartyTo(PurchaseRequest $purchaseRequest): bool
    {
        $organizationId = TenantContext::id();

        if ($organizationId === null) {
            return request()->user()?->isPlatformOperator() ?? false;
        }

        return in_array($organizationId, [
            (int) $purchaseRequest->buyer_organization_id,
            (int) $purchaseRequest->seller_organization_id,
        ], true);
    }
}
