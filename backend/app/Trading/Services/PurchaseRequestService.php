<?php

namespace App\Trading\Services;

use App\Marketplace\Models\DemandMatch;
use App\Marketplace\Models\Offer;
use App\Notifications\Services\NotificationManager;
use App\Trading\Exceptions\OfferExpiredException;
use App\Core\Exceptions\ApiRefused;
use App\Trading\Models\PurchaseRequest;
use App\Identity\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The rules of the buyer's ask, away from any one door.
 *
 * A purchase request is not a deal and not a reservation: creating one takes
 * nothing off the shelf; only the seller's acceptance holds stock, and a deal
 * is formed afterwards against that held stock. Which asks may be raised (the
 * offer must be on the market, the seller still trading, the quantity inside
 * the listing's own bounds), what each answer rewrites on the record, and the
 * notification the ask must never be born without — those decisions live here,
 * because the same rules must stand whether the ask arrives from the market
 * fast path, from a match, or from whatever door is built next.
 *
 * A refusal is spoken as `ApiRefused`, carrying the message, the
 * machine code, the status and the details the controller owes the client.
 */
class PurchaseRequestService
{
    public function __construct(
        protected InventoryReservationService $reservationService,
        protected NotificationManager $notificationManager,
    ) {}

    /**
     * The state gates of the seller's three answers and the buyer's two.
     *
     * The controller asks these *before* it reads the form, so a request that
     * can no longer be answered is refused for the reason it truly fails for —
     * "this ask is closed" — and not for the missing `reason` field behind it.
     * Each message names its own act because the client's retry differs per
     * one. Only an open ask can be accepted, rejected or countered; a request
     * that was already answered, withdrawn, or countered is not the buyer's
     * terms anymore.
     *
     * @throws ApiRefused
     */
    public function assertAcceptable(PurchaseRequest $purchaseRequest): void
    {
        if ($purchaseRequest->status !== PurchaseRequest::STATUS_REQUESTED) {
            throw new ApiRefused(
                'This request can no longer be accepted in its current state.',
                'PURCHASE_REQUEST_NOT_ACCEPTABLE'
            );
        }
    }

    /** @throws ApiRefused */
    public function assertRejectable(PurchaseRequest $purchaseRequest): void
    {
        if ($purchaseRequest->status !== PurchaseRequest::STATUS_REQUESTED) {
            throw new ApiRefused(
                'This request can no longer be rejected in its current state.',
                'PURCHASE_REQUEST_NOT_REJECTABLE'
            );
        }
    }

    /** @throws ApiRefused */
    public function assertProposable(PurchaseRequest $purchaseRequest): void
    {
        if ($purchaseRequest->status !== PurchaseRequest::STATUS_REQUESTED) {
            throw new ApiRefused(
                'This request can no longer be countered in its current state.',
                'PURCHASE_REQUEST_NOT_PROPOSABLE'
            );
        }
    }

    /** @throws ApiRefused */
    public function assertHasOpenProposal(PurchaseRequest $purchaseRequest): void
    {
        if ($purchaseRequest->status !== PurchaseRequest::STATUS_PROPOSED) {
            throw new ApiRefused(
                'There is no open proposal on this request.',
                'PURCHASE_REQUEST_NOT_PROPOSED'
            );
        }
    }

    /**
     * Withdrawing a request that already holds stock would leave a reservation
     * nobody owns, so only an unanswered ask may be withdrawn here; the dealt
     * case belongs to the deal/reservation cancellation path.
     *
     * @throws ApiRefused
     */
    public function assertWithdrawable(PurchaseRequest $purchaseRequest): void
    {
        if (!in_array($purchaseRequest->status, [PurchaseRequest::STATUS_REQUESTED, PurchaseRequest::STATUS_PROPOSED], true)) {
            throw new ApiRefused(
                'Only an unanswered request can be withdrawn.',
                'PURCHASE_REQUEST_NOT_CANCELLABLE'
            );
        }
    }

    /**
     * The buyer's ask raised straight from a live offer.
     *
     * This is the marketplace fast path's first write, and it is deliberately a
     * request rather than a hold: `POST /marketplace/reservations` used to let
     * a buyer lock `reserved_quantity` on a seller's listing without the seller
     * ever being asked, which is the inversion the deal model forbids. Creating
     * one record here moves no quantity at all; the seller's acceptance is what
     * runs the atomic reservation.
     *
     * No demand and no match back this request, so both foreign keys stay null
     * — the columns were already nullable because a request is fundamentally
     * about an offer, not about the search that found it.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ApiRefused
     */
    public function createFromOffer(Offer $offer, int $buyerOrgId, User $buyerUser, array $validated): PurchaseRequest
    {
        $buyerOrgId = (int) $buyerOrgId;

        if ((int) $offer->seller_organization_id === $buyerOrgId) {
            throw new ApiRefused(
                'لا ترفع طلب شراء على عرض منشأتك',
                'SELF_PURCHASE_FORBIDDEN'
            );
        }

        // Two different deaths share one refusal: the seller took the listing
        // down, or its own closing date passed while the label still read ACTIVE.
        // The public list, the nearby search and the promoted strip all bind
        // `expires_at`, so a buyer who reaches this door on an over-date card is
        // filing it from a cache or a stale tab — and the ask is refused here,
        // with the reason named, rather than after three screens of promise.
        if ($offer->status !== Offer::STATUS_ACTIVE || $offer->isExpired()) {
            throw new ApiRefused(
                'This offer is not on the market right now.',
                'OFFER_NOT_TRADABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['status' => $offer->status, 'expired' => $offer->isExpired()]
            );
        }

        // A suspended or rejected business is not allowed to trade, so its
        // listing must not collect requests either — the buyer would be waiting
        // on a seller the platform has already stopped.
        if (!$offer->sellerMayTrade()) {
            throw new ApiRefused(
                'البائع المصدِر لهذا العرض لا يستطيع التداول حالياً.',
                'SELLER_NOT_TRADING'
            );
        }

        $requestedQuantity = (float) $validated['requested_quantity'];

        // Both sentences carry the number the buyer has to type instead, because
        // "exceeds available" tells a buyer nothing about what to change. Arabic is
        // the language the platform refuses in everywhere else; these two were the
        // exception, and the web screen had to paste its own translation in front of
        // them.
        if ($requestedQuantity > (float) $offer->available_quantity) {
            throw new ApiRefused(
                'الكمية المطلوبة أكبر من المتاح لدى هذا العرض الآن؛ المتاح ' . (float) $offer->available_quantity . ' ' . $offer->unit . '.',
                'QUANTITY_EXCEEEDS_AVAILABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['available_quantity' => (float) $offer->available_quantity]
            );
        }

        $minOrder = (float) $offer->min_order_quantity;
        if ($requestedQuantity < $minOrder) {
            throw new ApiRefused(
                "هذا العرض لا يفتح خطاً بأقل من {$minOrder} {$offer->unit}.",
                'QUANTITY_BELOW_MIN_ORDER',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['min_order_quantity' => $minOrder]
            );
        }

        $purchaseRequest = PurchaseRequest::create([
            'purchase_request_number' => $this->nextNumber(),
            'demand_id' => null,
            'demand_match_id' => null,
            'offer_id' => $offer->id,
            'buyer_organization_id' => $buyerOrgId,
            'seller_organization_id' => $offer->seller_organization_id,
            'buyer_user_id' => $buyerUser->id,
            'requested_quantity' => $requestedQuantity,
            'unit' => $offer->unit,
            'requested_price' => $offer->unit_price,
            'currency_code' => $offer->currency_code,
            'delivery_preference' => $validated['delivery_preference'] ?? 'PICKUP',
            'status' => PurchaseRequest::STATUS_REQUESTED,
            'notes' => $validated['notes'] ?? null,
        ]);

        $this->notifySeller($purchaseRequest, $offer);

        return $purchaseRequest;
    }

    /**
     * The buyer's ask raised from a match the engine offered them.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ApiRefused
     */
    public function createFromMatch(DemandMatch $match, int $buyerOrgId, User $buyerUser, array $validated): PurchaseRequest
    {
        // A match is the engine's opinion at a point in time, and it can be
        // withdrawn (the offer sold out or was paused), consumed (this buyer
        // already asked), or simply old. Turning any of those into a new request
        // would let the same opportunity be taken twice.
        if ($match->status !== DemandMatch::STATUS_ACTIVE) {
            throw new ApiRefused(
                'لم تعد هذه المطابقة فرصة مفتوحة (حالتها: ' . $match->status . ').',
                'MATCH_NOT_ACTIONABLE'
            );
        }

        if ($match->expires_at && Carbon::now()->greaterThan($match->expires_at)) {
            $match->update(['status' => DemandMatch::STATUS_EXPIRED]);

            throw new ApiRefused(
                'انتهت مدة هذه المطابقة؛ أعد قراءة الطلب لترى العروض الحالية.',
                'MATCH_EXPIRED'
            );
        }

        if (!$match->offer || $match->offer->status !== Offer::STATUS_ACTIVE) {
            $match->update(['status' => DemandMatch::STATUS_INVALIDATED]);

            throw new ApiRefused(
                'العرض الذي بُنيت عليه هذه المطابقة لم يعد في السوق.',
                'OFFER_NOT_AVAILABLE'
            );
        }

        // The engine scored this pairing while the seller was still trading;
        // that can have changed since. A request queued against a stopped
        // business would only leave the buyer waiting for a decision the
        // platform has already frozen.
        if (!$match->offer->sellerMayTrade()) {
            throw new ApiRefused(
                'البائع المصدِر لهذا العرض لا يستطيع التداول حالياً.',
                'SELLER_NOT_TRADING'
            );
        }

        $requestedQuantity = (float) ($validated['requested_quantity'] ?? $match->requested_quantity);
        $maxQuantity = (float) $match->offer->available_quantity;
        if ($requestedQuantity > $maxQuantity) {
            throw new ApiRefused(
                'الكمية المطلوبة أكبر من المتاح لدى هذا العرض الآن؛ المتاح ' . $maxQuantity . ' ' . $match->offer->unit . '.',
                'QUANTITY_EXCEEEDS_AVAILABLE'
            );
        }

        $minOrder = (float) $match->offer->min_order_quantity;
        if ($requestedQuantity < $minOrder) {
            throw new ApiRefused(
                "هذا العرض لا يفتح خطاً بأقل من {$minOrder} {$match->offer->unit}.",
                'QUANTITY_BELOW_MIN_ORDER'
            );
        }

        $purchaseRequest = PurchaseRequest::create([
            'purchase_request_number' => $this->nextNumber(),
            'demand_id' => $match->demand_id,
            'demand_match_id' => $match->id,
            'offer_id' => $match->offer_id,
            'buyer_organization_id' => $buyerOrgId,
            'seller_organization_id' => $match->seller_organization_id,
            'buyer_user_id' => $buyerUser->id,
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

        return $purchaseRequest;
    }

    /**
     * Seller acceptance: the moment stock is actually held.
     *
     * Accepting runs the same atomic, locked reservation a direct buyer
     * reservation runs, so two sellers cannot accept two requests for the last
     * pallet. Run `assertAcceptable` before reading the form, then this.
     *
     * @throws ApiRefused
     */
    public function accept(PurchaseRequest $purchaseRequest, User $sellerUser, ?User $buyerUser, ?string $notes): PurchaseRequest
    {
        $offer = $purchaseRequest->offer;

        if (!$offer) {
            throw new ApiRefused(
                'The offer this request refers to no longer exists.',
                'OFFER_UNAVAILABLE'
            );
        }

        try {
            $reservation = $this->reservationService->reserve(
                $offer,
                $buyerUser ?? $sellerUser,
                (int) $purchaseRequest->buyer_organization_id,
                (float) $purchaseRequest->requested_quantity,
                $purchaseRequest->delivery_preference === 'DELIVERY' ? 'DELIVERY' : 'PICKUP',
                $notes ?? $purchaseRequest->notes
            );
        } catch (OfferExpiredException $e) {
            throw new ApiRefused(
                'The offer validity ended before this request could be accepted.',
                'OFFER_EXPIRED'
            );
        } catch (Exception $e) {
            // Includes "insufficient inventory": the seller is told the ask
            // could not be honoured rather than being shown a false success.
            throw new ApiRefused(
                $e->getMessage(),
                'PURCHASE_REQUEST_ACCEPT_FAILED'
            );
        }

        $purchaseRequest->update([
            'status' => PurchaseRequest::STATUS_ACCEPTED,
            'reservation_id' => $reservation->id,
            'seller_user_id' => $sellerUser->id,
            'notes' => $notes ?? $purchaseRequest->notes,
        ]);

        return $purchaseRequest;
    }

    /**
     * The seller's "no", with the reason the buyer is owed.
     *
     * @throws ApiRefused
     */
    public function reject(PurchaseRequest $purchaseRequest, User $sellerUser, string $reason): PurchaseRequest
    {
        $purchaseRequest->update([
            'status' => PurchaseRequest::STATUS_REJECTED,
            'seller_user_id' => $sellerUser->id,
            'notes' => $reason,
        ]);

        return $purchaseRequest;
    }

    /**
     * Negotiation records a counter-offer; it never edits the buyer's ask.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ApiRefused
     */
    public function propose(PurchaseRequest $purchaseRequest, User $sellerUser, array $validated): PurchaseRequest
    {
        $terms = array_filter([
            'quantity' => isset($validated['proposed_terms']['quantity']) ? (float) $validated['proposed_terms']['quantity'] : null,
            'unit_price' => isset($validated['proposed_terms']['unit_price']) ? (float) $validated['proposed_terms']['unit_price'] : null,
            'currency_code' => $validated['proposed_terms']['currency_code'] ?? $purchaseRequest->currency_code,
        ], fn ($value) => $value !== null);

        $changesQuantity = isset($terms['quantity']) && abs($terms['quantity'] - (float) $purchaseRequest->requested_quantity) > 0.0001;
        $changesPrice = isset($terms['unit_price']) && abs($terms['unit_price'] - (float) $purchaseRequest->requested_price) > 0.0000001;

        if (!$changesQuantity && !$changesPrice) {
            throw new ApiRefused(
                'A proposal must change the quantity or the price. Accept the request as it stands instead.',
                'PROPOSAL_HAS_NO_CHANGE'
            );
        }

        $purchaseRequest->update([
            'status' => PurchaseRequest::STATUS_PROPOSED,
            'seller_user_id' => $sellerUser->id,
            'proposed_terms' => $terms,
            'notes' => $validated['notes'] ?? $purchaseRequest->notes,
        ]);

        return $purchaseRequest;
    }

    /**
     * Buyer agreement to a counter-offer.
     *
     * Accepting a proposal does not create a deal and does not hold stock — it
     * rewrites the buyer's own ask onto the seller's terms and sends it back for
     * the seller to accept. Only the seller can ever put a quantity on
     * reservation, so the moment of commitment stays on one side of the
     * conversation.
     *
     * @throws ApiRefused
     */
    public function adoptProposal(PurchaseRequest $purchaseRequest): PurchaseRequest
    {
        $terms = $purchaseRequest->proposed_terms ?? [];

        $purchaseRequest->update([
            'requested_quantity' => (float) ($terms['quantity'] ?? $purchaseRequest->requested_quantity),
            'requested_price' => (float) ($terms['unit_price'] ?? $purchaseRequest->requested_price),
            'currency_code' => $terms['currency_code'] ?? $purchaseRequest->currency_code,
            'status' => PurchaseRequest::STATUS_REQUESTED,
            'proposed_terms' => null,
        ]);

        return $purchaseRequest;
    }

    /**
     * The buyer withdraws an ask that has not been answered yet. Withdrawing a
     * request that already holds stock would leave a reservation nobody owns,
     * so that case belongs to the deal/reservation cancellation path instead.
     *
     * @throws ApiRefused
     */
    public function cancel(PurchaseRequest $purchaseRequest, ?string $reason): PurchaseRequest
    {
        $purchaseRequest->update([
            'status' => PurchaseRequest::STATUS_CANCELLED,
            'notes' => $reason ?? $purchaseRequest->notes,
        ]);

        return $purchaseRequest;
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

    private function nextNumber(): string
    {
        return 'PR-' . now()->format('ymd') . '-' . strtoupper(Str::random(6));
    }
}
