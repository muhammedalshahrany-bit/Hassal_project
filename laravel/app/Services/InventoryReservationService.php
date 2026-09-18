<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\PurchaseRequest;
use App\Models\Deal;
use App\Models\DealItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class InventoryReservationService
{
    /**
     * Reserve inventory atomically using pessimistic row locking (lockForUpdate).
     * Strictly prevents Double-Selling, Race Conditions, and Negative Inventory.
     *
     * @param int $offerId
     * @param float $quantity
     * @param int $buyerOrgId
     * @param int $buyerUserId
     * @param string $deliveryPreference
     * @param string|null $notes
     * @return PurchaseRequest
     * @throws ValidationException
     */
    public function createAtomicReservation(
        int $offerId,
        float $quantity,
        int $buyerOrgId,
        int $buyerUserId,
        string $deliveryPreference = 'delivery',
        ?string $notes = null
    ): PurchaseRequest {
        return DB::transaction(function () use ($offerId, $quantity, $buyerOrgId, $buyerUserId, $deliveryPreference, $notes) {
            // Pessimistic Row Lock (SELECT ... FOR UPDATE)
            $offer = Offer::where('id', $offerId)->lockForUpdate()->first();

            if (!$offer) {
                throw ValidationException::withMessages([
                    'offer_id' => ['العرض التجاري المطلوب غير موجود.'],
                ]);
            }

            if ($offer->status !== 'active') {
                throw ValidationException::withMessages([
                    'offer_id' => ['هذا العرض التجاري غير نشط أو تم سحبه من السوق.'],
                ]);
            }

            if ($quantity < (float)$offer->min_order_quantity) {
                throw ValidationException::withMessages([
                    'quantity' => ["الحد الأدنى للطلب (MOQ) هو {$offer->min_order_quantity} وحدة."],
                ]);
            }

            if ($offer->max_order_quantity && $quantity > (float)$offer->max_order_quantity) {
                throw ValidationException::withMessages([
                    'quantity' => ["الكمية تتجاوز الحد الأقصى المسموح به للطلب ({$offer->max_order_quantity} وحدة)."],
                ]);
            }

            if ((float)$offer->available_quantity < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => [
                        "عذراً، الكمية المتاحة حالياً في المستودع ({$offer->available_quantity}) لا تكفي لتلبية طلبك ({$quantity})."
                    ],
                ]);
            }

            // Atomic decrement available and increment reserved
            $offer->available_quantity = (float)$offer->available_quantity - $quantity;
            $offer->reserved_quantity = (float)$offer->reserved_quantity + $quantity;
            $offer->save();

            $timeoutMinutes = (int) config('hassal.reservation_timeout_minutes', 30);
            $totalPrice = round($quantity * (float)$offer->unit_price, 2);

            $purchaseRequest = PurchaseRequest::create([
                'offer_id' => $offer->id,
                'buyer_organization_id' => $buyerOrgId,
                'buyer_user_id' => $buyerUserId,
                'quantity' => $quantity,
                'unit_price' => $offer->unit_price,
                'total_price' => $totalPrice,
                'currency' => $offer->currency ?? 'SAR',
                'delivery_preference' => $deliveryPreference,
                'status' => 'pending',
                'reserved_until' => Carbon::now()->addMinutes($timeoutMinutes),
                'notes' => $notes,
            ]);

            return $purchaseRequest;
        });
    }

    /**
     * Release expired reservations and return inventory back to available stock.
     */
    public function releaseExpiredReservations(): int
    {
        $expiredCount = 0;

        DB::transaction(function () use (&$expiredCount) {
            $expiredRequests = PurchaseRequest::where('status', 'pending')
                ->where('reserved_until', '<', Carbon::now())
                ->lockForUpdate()
                ->get();

            foreach ($expiredRequests as $request) {
                $offer = Offer::where('id', $request->offer_id)->lockForUpdate()->first();
                if ($offer) {
                    $offer->available_quantity = (float)$offer->available_quantity + (float)$request->quantity;
                    $offer->reserved_quantity = max(0, (float)$offer->reserved_quantity - (float)$request->quantity);
                    $offer->save();
                }

                $request->status = 'expired';
                $request->rejection_reason = 'انتهت مهلة الحجز المحددة دون تأكيد من البائع';
                $request->save();

                $expiredCount++;
            }
        });

        return $expiredCount;
    }
}
