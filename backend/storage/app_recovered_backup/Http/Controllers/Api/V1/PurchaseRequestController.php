<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\OfferExpiredException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\DemandMatch;
use App\Models\Offer;
use App\Models\PurchaseRequest;
use App\Services\InventoryReservationService;
use App\Services\Notifications\NotificationManager;
use App\Services\TenantContext;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The buyer's ask, reviewed by the seller.
 *
 * A purchase request is not a deal and not a reservation (§6). Creating one
 * takes nothing off the shelf; only the seller's acceptance holds stock, and a
 * deal is formed afterwards against that held stock. Accepting therefore has a
 * real consequence: it runs the same atomic, locked reservation a direct buyer
 * reservation runs, so two sellers cannot accept two requests for the last
 * pallet.
 */
class PurchaseRequestController extends Controller
{
    public function __construct(
        protected InventoryReservationService $reservationService,
        protected NotificationManager $notificationManager,
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
     * The buyer's ask raised straight from a live offer.
     *
     * This is the marketplace fast path's first write, and it is deliberately a
     * request rather than a hold: `POST /marketplace/reservations` used to let
     * a buyer lock `reserved_quantity` on a seller's listing without the seller
     * ever being asked, which is the inversion §19–§26 forbids. Creating one
     * record here moves no quantity at all; the seller's `accept()` is what
     * runs the atomic reservation.
     *
     * No demand and no match back this request, so both foreign keys stay null
     * — the columns were already nullable because a request is fundamentally
     * about an offer, not about the search that found it.
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

        if ((int) $offer->seller_organization_id === (int) $buyerOrgId) {
            return ApiResponse::error(
                'لا ترفع طلب شراء على عرض منشأتك',
                'SELF_PURCHASE_FORBIDDEN',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Two different deaths share one refusal: the seller took the listing
        // down, or its own closing date passed while the label still read ACTIVE.
        // The public list, the nearby search and the promoted strip all bound
        // `expires_at`, so a buyer who reaches this door on an over-date card is
        // filing it from a cache or a stale tab — and the ask is refused here,
        // with the reason named, rather than after three screens of promise.
        if ($offer->status !== Offer::STATUS_ACTIVE || $offer->isExpired()) {
            return ApiResponse::error(
                'This offer is not on the market right now.',
                'OFFER_NOT_TRADABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['status' => $offer->status, 'expired' => $offer->isExpired()]
            );
        }

        // A listing whose own validity has ended is not on the market even while
        // its label still says ACTIVE, because no process rewrites that label
        // (`Offer::isExpired` says why). Refusing here is the same bound the
        // public list, the nearby search and the promoted strip already apply:
        // a request filed against a finished price would be accepted by the
        // buyer, answered by the seller, and only die at the moment stock is
        // held — three screens of promise the platform cannot keep.
        if ($offer->isExpired()) {
            return ApiResponse::error(
                'This offer is not on the market right now.',
                'OFFER_NOT_TRADABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['status' => $offer->status, 'expired' => true]
            );
        }

        // A suspended or rejected business is not allowed to trade, so its
        // listing must not collect requests either — the buyer would be waiting
        // on a seller the platform has already stopped.
        if (!$offer->sellerMayTrade()) {
            return ApiResponse::error(
                'البائع المصدِر لهذا العرض لا يستطيع التداول حالياً.',
                'SELLER_NOT_TRADING',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $requestedQuantity = (float) $validated['requested_quantity'];

        if ($requestedQuantity > (float) $offer->available_quantity) {
            return ApiResponse::error(
                'Requested quantity exceeds available quantity',
                'QUANTITY_EXCEEEDS_AVAILABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['available_quantity' => (float) $offer->available_quantity]
            );
        }

        $minOrder = (float) $offer->min_order_quantity;
        if ($requestedQuantity < $minOrder) {
            return ApiResponse::error(
                "This offer does not open a line below {$minOrder} {$offer->unit}.",
                'QUANTITY_BELOW_MIN_ORDER',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['min_order_quantity' => $minOrder]
            );
        }

        $purchaseRequest = PurchaseRequest::create([
            'purchase_request_number' => 'PR-' . now()->format('ymd') . '-' . strtoupper(Str::random(6)),
            'demand_id' => null,
            'demand_match_id' => null,
            'offer_id' => $offer->id,
            'buyer_organization_id' => $buyerOrgId,
            'seller_organization_id' => $offer->seller_organization_id,
            'buyer_user_id' => $request->user()->id,
            'requested_quantity' => $requestedQuantity,
            'unit' => $offer->unit,
            'requested_price' => $offer->unit_price,
            'currency_code' => $offer->currency_code,
            'delivery_preference' => $validated['delivery_preference'] ?? 'PICKUP',
            'status' => PurchaseRequest::STATUS_REQUESTED,
            'notes' => $validated['notes'] ?? null,
        ]);

        $this->notifySeller($purchaseRequest, $offer);

        return ApiResponse::success(
            $purchaseRequest->load(['offer', 'buyerOrganization', 'sellerOrganization']),
            'Purchase request created. No stock is held until the seller reviews it.',
            Response::HTTP_CREATED
        );
    }

    /**
     * Tell the selling business that a request is waiting on it.
     *
     * The review board is polling; a seller who is not watching it never sees
     * the ask, and the buyer has no way to say so. Both creation paths run
     * through here so the notification cannot be forgotten on one of them.
     */
    private function notifySeller(PurchaseRequest $purchaseRequest, Offer $offer): void
    {
        if (!$offer->seller) {
            return;
        }

        $this->notificationManager->notifyOrganization(
            $offer->seller,
            'طلب شراء جديد',
            "طلب {$purchaseRequest->requested_quantity} {$purchaseRequest->unit} من العرض «{$offer->title_ar}» بانتظار قرارك.",
            [
                'type' => 'PURCHASE_REQUEST',
                'purchase_request_id' => $purchaseRequest->id,
                'offer_id' => $offer->id,
                'organization_id' => $offer->seller_organization_id,
            ]
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

        // A match is the engine's opinion at a point in time, and it can be
        // withdrawn (the offer sold out or was paused), consumed (this buyer
        // already asked), or simply old. Turning any of those into a new request
        // would let the same opportunity be taken twice.
        if ($match->status !== DemandMatch::STATUS_ACTIVE) {
            return ApiResponse::error(
                'This match is no longer an open opportunity (' . $match->status . ').',
                'MATCH_NOT_ACTIONABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($match->expires_at && Carbon::now()->greaterThan($match->expires_at)) {
            $match->update(['status' => DemandMatch::STATUS_EXPIRED]);

            return ApiResponse::error(
                'This match expired. Refresh the demand to see current offers.',
                'MATCH_EXPIRED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (!$match->offer || $match->offer->status !== Offer::STATUS_ACTIVE) {
            $match->update(['status' => DemandMatch::STATUS_INVALIDATED]);

            return ApiResponse::error(
                'The offer behind this match is no longer on the market.',
                'OFFER_NOT_AVAILABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // The engine scored this pairing while the seller was still trading;
        // that can have changed since. A request queued against a stopped
        // business would only leave the buyer waiting for a decision the
        // platform has already frozen.
        if (!$match->offer->sellerMayTrade()) {
            return ApiResponse::error(
                'البائع المصدِر لهذا العرض لا يستطيع التداول حالياً.',
                'SELLER_NOT_TRADING',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $validated = $request->validate([
            'requested_quantity' => ['nullable', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:500'],
            'delivery_preference' => ['nullable', 'in:PICKUP,DELIVERY'],
        ]);

        $requestedQuantity = (float) ($validated['requested_quantity'] ?? $match->requested_quantity);
        $maxQuantity = (float) $match->offer->available_quantity;
        if ($requestedQuantity > $maxQuantity) {
            return ApiResponse::error('Requested quantity exceeds available quantity', 'QUANTITY_EXCEEEDS_AVAILABLE', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $minOrder = (float) $match->offer->min_order_quantity;
        if ($requestedQuantity < $minOrder) {
            return ApiResponse::error(
                "This offer does not open a line below {$minOrder} {$match->offer->unit}.",
                'QUANTITY_BELOW_MIN_ORDER',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $purchaseRequest = PurchaseRequest::create([
            'purchase_request_number' => 'PR-' . now()->format('ymd') . '-' . strtoupper(Str::random(6)),
            'demand_id' => $match->demand_id,
            'demand_match_id' => $match->id,
            'offer_id' => $match->offer_id,
            'buyer_organization_id' => $buyerOrgId,
            'seller_organization_id' => $match->seller_organization_id,
            'buyer_user_id' => $request->user()->id,
            'requested_quantity' => $requestedQuantity,
            'unit' => $match->offer->unit,
            'requested_price' => $match->offer->unit_price,
            'currency_code' => $match->offer->currency_code,
            'delivery_preference' => $validated['delivery_preference'] ?? 'PICKUP',
            'status' => PurchaseRequest::STATUS_REQUESTED,
            'notes' => $validated['notes'] ?? null,
        ]);

        $match->update(['status' => DemandMatch::STATUS_USED]);

        $this->notifySeller($purchaseRequest, $match->offer);

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

        // Only an open ask can be accepted. A request that was already
        // answered, withdrawn, or countered is not the buyer's terms anymore.
        if ($purchaseRequest->status !== PurchaseRequest::STATUS_REQUESTED) {
            return ApiResponse::error(
                'This request can no longer be accepted in its current state.',
                'PURCHASE_REQUEST_NOT_ACCEPTABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $offer = $purchaseRequest->offer;

        if (!$offer) {
            return ApiResponse::error('The offer this request refers to no longer exists.', 'OFFER_UNAVAILABLE', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $buyerUser = $purchaseRequest->buyerUser ?? $request->user();

        try {
            $reservation = $this->reservationService->reserve(
                $offer,
                $buyerUser,
                (int) $purchaseRequest->buyer_organization_id,
                (float) $purchaseRequest->requested_quantity,
                $purchaseRequest->delivery_preference === 'DELIVERY' ? 'DELIVERY' : 'PICKUP',
                $request->input('notes', $purchaseRequest->notes)
            );
        } catch (OfferExpiredException $e) {
            return ApiResponse::error(
                'The offer validity ended before this request could be accepted.',
                'OFFER_EXPIRED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        } catch (Exception $e) {
            // Includes "insufficient inventory": the seller is told the ask
            // could not be honoured rather than being shown a false success.
            return ApiResponse::error(
                $e->getMessage(),
                'PURCHASE_REQUEST_ACCEPT_FAILED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $purchaseRequest->update([
            'status' => PurchaseRequest::STATUS_ACCEPTED,
            'reservation_id' => $reservation->id,
            'seller_user_id' => $request->user()->id,
            'notes' => $request->input('notes', $purchaseRequest->notes),
        ]);

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

        if ($purchaseRequest->status !== PurchaseRequest::STATUS_REQUESTED) {
            return ApiResponse::error(
                'This request can no longer be rejected in its current state.',
                'PURCHASE_REQUEST_NOT_REJECTABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $purchaseRequest->update([
            'status' => PurchaseRequest::STATUS_REJECTED,
            'seller_user_id' => $request->user()->id,
            'notes' => $validated['reason'],
        ]);

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

        if ($purchaseRequest->status !== PurchaseRequest::STATUS_REQUESTED) {
            return ApiResponse::error(
                'This request can no longer be countered in its current state.',
                'PURCHASE_REQUEST_NOT_PROPOSABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $validated = $request->validate([
            'proposed_terms' => ['required', 'array'],
            'proposed_terms.quantity' => ['nullable', 'numeric', 'min:0.01'],
            'proposed_terms.unit_price' => ['nullable', 'numeric', 'min:0.0001'],
            'proposed_terms.currency_code' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $terms = array_filter([
            'quantity' => isset($validated['proposed_terms']['quantity']) ? (float) $validated['proposed_terms']['quantity'] : null,
            'unit_price' => isset($validated['proposed_terms']['unit_price']) ? (float) $validated['proposed_terms']['unit_price'] : null,
            'currency_code' => $validated['proposed_terms']['currency_code'] ?? $purchaseRequest->currency_code,
        ], fn ($value) => $value !== null);

        $changesQuantity = isset($terms['quantity']) && abs($terms['quantity'] - (float) $purchaseRequest->requested_quantity) > 0.0001;
        $changesPrice = isset($terms['unit_price']) && abs($terms['unit_price'] - (float) $purchaseRequest->requested_price) > 0.0000001;

        if (!$changesQuantity && !$changesPrice) {
            return ApiResponse::error(
                'A proposal must change the quantity or the price. Accept the request as it stands instead.',
                'PROPOSAL_HAS_NO_CHANGE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $purchaseRequest->update([
            'status' => PurchaseRequest::STATUS_PROPOSED,
            'seller_user_id' => $request->user()->id,
            'proposed_terms' => $terms,
            'notes' => $validated['notes'] ?? $purchaseRequest->notes,
        ]);

        return ApiResponse::success($purchaseRequest, 'Seller proposal submitted');
    }

    /**
     * Buyer agreement to a counter-offer.
     *
     * Accepting a proposal does not create a deal and does not hold stock — it
     * rewrites the buyer's own ask onto the seller's terms and sends it back for
     * the seller to accept. Only the seller can ever put a quantity on
     * reservation, so the moment of commitment stays on one side of the
     * conversation.
     */
    public function acceptProposal(Request $request, int $id): JsonResponse
    {
        $purchaseRequest = PurchaseRequest::findOrFail($id);

        if ((int) $purchaseRequest->buyer_organization_id !== TenantContext::id()) {
            return ApiResponse::error('Only the buyer organization may answer this proposal', 'BUYER_PROPOSAL_DENIED', Response::HTTP_FORBIDDEN);
        }

        if ($purchaseRequest->status !== PurchaseRequest::STATUS_PROPOSED) {
            return ApiResponse::error(
                'There is no open proposal on this request.',
                'PURCHASE_REQUEST_NOT_PROPOSED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $terms = $purchaseRequest->proposed_terms ?? [];

        $purchaseRequest->update([
            'requested_quantity' => (float) ($terms['quantity'] ?? $purchaseRequest->requested_quantity),
            'requested_price' => (float) ($terms['unit_price'] ?? $purchaseRequest->requested_price),
            'currency_code' => $terms['currency_code'] ?? $purchaseRequest->currency_code,
            'status' => PurchaseRequest::STATUS_REQUESTED,
            'proposed_terms' => null,
        ]);

        return ApiResponse::success(
            $purchaseRequest,
            'Proposal adopted. The request is waiting for the seller to confirm it.'
        );
    }

    /**
     * The buyer withdraws an ask that has not been answered yet. Withdrawing a
     * request that already holds stock would leave a reservation nobody owns,
     * so that case belongs to the deal/reservation cancellation path instead.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $purchaseRequest = PurchaseRequest::findOrFail($id);

        if ((int) $purchaseRequest->buyer_organization_id !== TenantContext::id()) {
            return ApiResponse::error('Only the buyer organization may withdraw this request', 'BUYER_CANCEL_DENIED', Response::HTTP_FORBIDDEN);
        }

        if (!in_array($purchaseRequest->status, [PurchaseRequest::STATUS_REQUESTED, PurchaseRequest::STATUS_PROPOSED], true)) {
            return ApiResponse::error(
                'Only an unanswered request can be withdrawn.',
                'PURCHASE_REQUEST_NOT_CANCELLABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $purchaseRequest->update([
            'status' => PurchaseRequest::STATUS_CANCELLED,
            'notes' => $validated['reason'] ?? $purchaseRequest->notes,
        ]);

        return ApiResponse::success($purchaseRequest, 'Purchase request withdrawn');
    }

    /**
     * Is the acting tenant one of the two organizations named on this request?
     */
    protected function isPartyTo(PurchaseRequest $purchaseRequest): bool
    {
        $organizationId = TenantContext::id();

        if ($organizationId === null) {
            return request()->user()?->hasRole('SuperAdmin') ?? false;
        }

        return in_array($organizationId, [
            (int) $purchaseRequest->buyer_organization_id,
            (int) $purchaseRequest->seller_organization_id,
        ], true);
    }
}
