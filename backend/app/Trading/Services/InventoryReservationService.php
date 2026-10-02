<?php

namespace App\Trading\Services;

use App\Platform\Services\SystemSettingsService;
use App\Platform\Services\WebhookDispatcherService;

use App\Trading\Exceptions\OfferExpiredException;
use App\Marketplace\Models\Offer;
use App\Trading\Models\Reservation;
use App\Identity\Models\User;
use App\Core\Support\LockOrder;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryReservationService
{
    public function __construct(
        protected SystemSettingsService $settingsService,
        protected WebhookDispatcherService $webhookDispatcher
    ) {}

    /**
     * Atomically reserves stock from an active offer with concurrency locking.
     */
    public function reserve(Offer $offer, User $buyerUser, int $buyerOrgId, float $quantity, string $deliveryPreference = 'PICKUP', ?string $notes = null): Reservation
    {
        try {
            return $this->reserveInTransaction($offer, $buyerUser, $buyerOrgId, $quantity, $deliveryPreference, $notes);
        } catch (OfferExpiredException $e) {
            // The transaction above was rolled back, so this correction could
            // not have been persisted inside it.
            Offer::where('id', $e->offerId)->update([
                'status' => Offer::STATUS_EXPIRED,
                'updated_at' => Carbon::now(),
            ]);

            throw $e;
        }
    }

    /**
     * @throws Exception
     */
    protected function reserveInTransaction(Offer $offer, User $buyerUser, int $buyerOrgId, float $quantity, string $deliveryPreference, ?string $notes): Reservation
    {
        return DB::transaction(function () use ($offer, $buyerUser, $buyerOrgId, $quantity, $deliveryPreference, $notes) {
            // Pessimistic lock on the offer record to prevent race conditions & double allocation
            $lockedOffer = Offer::where('id', $offer->id)->lockForUpdate()->firstOrFail();

            if ($lockedOffer->status !== Offer::STATUS_ACTIVE) {
                throw new Exception("Cannot reserve from an offer that is not ACTIVE (Current status: {$lockedOffer->status})");
            }

            if ($lockedOffer->expires_at && Carbon::now()->greaterThan($lockedOffer->expires_at)) {
                throw new OfferExpiredException((int) $lockedOffer->id);
            }

            if ($quantity < (float)$lockedOffer->min_order_quantity) {
                throw new Exception("Requested quantity ({$quantity}) is below the minimum order quantity ({$lockedOffer->min_order_quantity})");
            }

            if ($lockedOffer->max_order_quantity && $quantity > (float)$lockedOffer->max_order_quantity) {
                throw new Exception("Requested quantity ({$quantity}) exceeds the maximum order quantity ({$lockedOffer->max_order_quantity})");
            }

            if ($quantity > (float)$lockedOffer->available_quantity) {
                throw new Exception("Insufficient inventory available. Requested: {$quantity}, Available: {$lockedOffer->available_quantity}");
            }

            // Deduct available quantity and increment reserved quantity
            $newAvailable = (float)$lockedOffer->available_quantity - $quantity;
            $newReserved = (float)$lockedOffer->reserved_quantity + $quantity;

            $status = Offer::statusAfterQuantityChange($lockedOffer->status, $newAvailable);

            $lockedOffer->forceFill([
                'available_quantity' => $newAvailable,
                'reserved_quantity' => $newReserved,
                'status' => $status,
            ])->save();

            $timeoutMinutes = (int) $this->settingsService->get(
                'reservation.timeout',
                config('hassal.reservations.default_timeout_minutes')
            );
            $expiresAt = Carbon::now()->addMinutes($timeoutMinutes);

            $unitPrice = (float)$lockedOffer->unit_price;
            $totalAmount = round($quantity * $unitPrice, 4);

            // reservation_number is UNIQUE, so it must not be something this
            // request has a chance of colliding with: a date plus a random
            // suffix, the same shape the offers and deals use.
            $reservationNumber = 'RES-' . now()->format('ymd') . '-' . strtoupper(Str::random(8));

            $reservation = Reservation::create([
                'reservation_number' => $reservationNumber,
                'offer_id' => $lockedOffer->id,
                'buyer_organization_id' => $buyerOrgId,
                'buyer_user_id' => $buyerUser->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'currency_code' => $lockedOffer->currency_code,
                'total_amount' => $totalAmount,
                'delivery_preference' => $deliveryPreference,
                'status' => Reservation::STATUS_ACTIVE,
                'expires_at' => $expiresAt,
                'notes' => $notes,
            ]);

            // Dispatch webhook for inventory reservation
            $this->webhookDispatcher->dispatch('inventory.reserved', [
                'reservation_id' => $reservation->id,
                'reservation_number' => $reservation->reservation_number,
                'offer_id' => $lockedOffer->id,
                'quantity' => $quantity,
                'expires_at' => $expiresAt->toIso8601String(),
            ], $lockedOffer->seller_organization_id);

            return $reservation;
        });
    }

    /**
     * Releases or cancels an active reservation, returning stock to the offer.
     *
     * @return bool false when the hold had already been resolved and nothing moved
     */
    public function release(Reservation $reservation, string $reason = 'Buyer cancelled reservation'): bool
    {
        return $this->finalize($reservation, Reservation::STATUS_CANCELLED, $reason);
    }

    /**
     * Ends a hold that was not converted into a deal in time.
     *
     * Kept apart from release() on purpose: a timeout is not somebody's
     * decision, and writing it into cancelled_at and cancellation_reason would
     * leave the history claiming a cancellation nobody made.
     */
    public function expire(Reservation $reservation): bool
    {
        return $this->finalize($reservation, Reservation::STATUS_EXPIRED);
    }

    /**
     * The one place a hold comes off the shelf, whichever end it came to.
     *
     * Locks in `LockOrder` sequence: the offer, then the reservation. The
     * previous shape here was reservation-then-offer, while `reserveInTransaction()`
     * locked the offer alone — a hold being taken and a hold being released
     * therefore walked through `offers` in opposite directions, which is the
     * pair that deadlocks under a busy minute on one popular listing.
     *
     * Reading `offer_id` unlocked first is safe because it is written once, when
     * the reservation is created, and never changed. The lock on the
     * reservation is what guards its *status*, and that is re-checked after the
     * lock is held.
     */
    protected function finalize(Reservation $reservation, string $status, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($reservation, $status, $reason) {
            $offerId = (int) $reservation->offer_id;

            $lockedOffer = Offer::where('id', $offerId)->lockForUpdate()->firstOrFail();
            $lockedReservation = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            LockOrder::assertBefore('offers', 'reservations');

            if ($lockedReservation->status !== Reservation::STATUS_ACTIVE) {
                return false; // Already resolved
            }

            $quantity = (float)$lockedReservation->quantity;
            $newReserved = max(0, (float)$lockedOffer->reserved_quantity - $quantity);
            $newAvailable = (float)$lockedOffer->available_quantity + $quantity;

            $offerStatus = Offer::statusAfterQuantityChange($lockedOffer->status, $newAvailable);

            $lockedOffer->forceFill([
                'available_quantity' => $newAvailable,
                'reserved_quantity' => $newReserved,
                'status' => $offerStatus,
            ])->save();

            $resolution = ['status' => $status];

            if ($status === Reservation::STATUS_CANCELLED) {
                $resolution['cancelled_at'] = Carbon::now();
                $resolution['cancellation_reason'] = $reason;
            }

            $lockedReservation->update($resolution);

            // Dispatch webhook
            $this->webhookDispatcher->dispatch('inventory.released', [
                'reservation_id' => $lockedReservation->id,
                'reservation_number' => $lockedReservation->reservation_number,
                'offer_id' => $lockedOffer->id,
                'quantity' => $quantity,
                'reason' => $reason ?? 'Reservation timed out',
            ], $lockedOffer->seller_organization_id);

            return true;
        });
    }

    /**
     * Sweeps and expires all timed-out reservations, restoring their stock.
     *
     * The grace window matters because the sweep runs on the same minute a deal
     * may be being confirmed: a hold is only released once it is provably dead,
     * and never while its owner is mid-transaction.
     */
    public function sweepExpiredReservations(): int
    {
        $expiredCount = 0;
        $cutoff = Carbon::now()->subSeconds(
            max(0, (int) config('hassal.reservations.expiry_grace_seconds'))
        );

        $expiredReservations = Reservation::where('status', Reservation::STATUS_ACTIVE)
            ->where('expires_at', '<', $cutoff)
            ->get();

        foreach ($expiredReservations as $reservation) {
            try {
                $this->expire($reservation);
                $expiredCount++;
            } catch (Exception $e) {
                // One poisoned row must not stop the rest of the shelf from
                // coming back to market — but it must not vanish silently.
                report($e);
            }
        }

        return $expiredCount;
    }
}
