<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\Reservation;
use App\Models\User;
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
        return DB::transaction(function () use ($offer, $buyerUser, $buyerOrgId, $quantity, $deliveryPreference, $notes) {
            // Pessimistic lock on the offer record to prevent race conditions & double allocation
            $lockedOffer = Offer::where('id', $offer->id)->lockForUpdate()->firstOrFail();

            if ($lockedOffer->status !== Offer::STATUS_ACTIVE) {
                throw new Exception("Cannot reserve from an offer that is not ACTIVE (Current status: {$lockedOffer->status})");
            }

            if ($lockedOffer->expires_at && Carbon::now()->greaterThan($lockedOffer->expires_at)) {
                $lockedOffer->update(['status' => Offer::STATUS_EXPIRED]);
                throw new Exception("The offer has already expired.");
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

            $status = $newAvailable <= 0.0001 ? Offer::STATUS_FULLY_RESERVED : Offer::STATUS_ACTIVE;

            $lockedOffer->update([
                'available_quantity' => $newAvailable,
                'reserved_quantity' => $newReserved,
                'status' => $status,
            ]);

            $timeoutMinutes = (int)$this->settingsService->get('reservation.timeout', env('PLATFORM_RESERVATION_TIMEOUT_MINUTES', 30));
            $expiresAt = Carbon::now()->addMinutes($timeoutMinutes);

            $unitPrice = (float)$lockedOffer->unit_price;
            $totalAmount = round($quantity * $unitPrice, 4);

            $reservationNumber = 'RES-' . strtoupper(Str::random(4)) . '-' . rand(1000, 9999);

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
     */
    public function release(Reservation $reservation, string $reason = 'Buyer cancelled reservation'): void
    {
        DB::transaction(function () use ($reservation, $reason) {
            $lockedReservation = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            if ($lockedReservation->status !== Reservation::STATUS_ACTIVE) {
                return; // Already resolved
            }

            $lockedOffer = Offer::where('id', $lockedReservation->offer_id)->lockForUpdate()->firstOrFail();

            $quantity = (float)$lockedReservation->quantity;
            $newReserved = max(0, (float)$lockedOffer->reserved_quantity - $quantity);
            $newAvailable = (float)$lockedOffer->available_quantity + $quantity;

            $offerStatus = $lockedOffer->status;
            if ($offerStatus === Offer::STATUS_FULLY_RESERVED && $newAvailable > 0) {
                $offerStatus = Offer::STATUS_ACTIVE;
            }

            $lockedOffer->update([
                'available_quantity' => $newAvailable,
                'reserved_quantity' => $newReserved,
                'status' => $offerStatus,
            ]);

            $lockedReservation->update([
                'status' => Reservation::STATUS_CANCELLED,
                'cancelled_at' => Carbon::now(),
                'cancellation_reason' => $reason,
            ]);

            // Dispatch webhook
            $this->webhookDispatcher->dispatch('inventory.released', [
                'reservation_id' => $lockedReservation->id,
                'reservation_number' => $lockedReservation->reservation_number,
                'offer_id' => $lockedOffer->id,
                'quantity' => $quantity,
                'reason' => $reason,
            ], $lockedOffer->seller_organization_id);
        });
    }

    /**
     * Sweeps and expires all timed-out reservations, restoring their stock.
     */
    public function sweepExpiredReservations(): int
    {
        $expiredCount = 0;
        $now = Carbon::now();

        $expiredReservations = Reservation::where('status', Reservation::STATUS_ACTIVE)
            ->where('expires_at', '<', $now)
            ->get();

        foreach ($expiredReservations as $reservation) {
            try {
                $this->release($reservation, 'Reservation expired due to timeout window');
                $reservation->update(['status' => Reservation::STATUS_EXPIRED]);
                $expiredCount++;
            } catch (Exception $e) {
                // Log and continue to sweep other reservations
            }
        }

        return $expiredCount;
    }
}
