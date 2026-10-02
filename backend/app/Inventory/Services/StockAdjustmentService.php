<?php

namespace App\Inventory\Services;

use App\Core\Exceptions\ApiRefused;
use App\Inventory\Models\InventoryMovement;
use App\Marketplace\Models\Offer;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one place HASSAL's stock numbers are corrected by hand.
 *
 * What this replaced was a controller that took a warehouse and a product
 * variant and wrote a movement reading `previous_quantity = 0, new_quantity = 0`
 * against them. Nothing in this platform tracks stock per warehouse and variant
 * — the only quantity it holds is an offer's `available_quantity` — so that row
 * asserted nothing true, and the numbers it appeared to describe did not move. An
 * adjustment therefore names the *offer* it adjusts, and changes that offer in the
 * same breath as the ledger entry that explains the change.
 *
 * Two invariants decide whether a correction is allowed, and both belong here
 * rather than to a caller: `reserved + sold + available <= total`, which the
 * database also enforces, and "a movement that describes goods arriving cannot
 * take quantity away". The first is why a reduction is capped and the answer
 * names the cap; the second is why a negative `IN` is refused instead of quietly
 * becoming a return of goods that never came back.
 */
class StockAdjustmentService
{
    /**
     * Move an offer's quantities and write the movement that says why.
     *
     * @param array<string, mixed> $validated
     *
     * @return InventoryMovement the ledger row, already saved with the offer it adjusted
     *
     * @throws ApiRefused with the exact envelope the API answers with
     */
    public function adjust(array $validated, int $orgId, int $userId): InventoryMovement
    {
        $quantity = (float) $validated['quantity'];

        if ($quantity === 0.0) {
            throw new ApiRefused(
                'الكمية لا يمكن أن تكون صفراً',
                'QUANTITY_REQUIRED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Restock and return describe goods arriving, so they cannot be
        // negative. Only a count correction may take quantity away.
        if (in_array($validated['type'], [InventoryMovement::TYPE_IN, InventoryMovement::TYPE_RETURNED], true)
            && $quantity < 0) {
            throw new ApiRefused(
                'الكمية في هذا النوع من الحركات يجب أن تكون أكبر من صفر',
                'QUANTITY_MUST_BE_POSITIVE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // The offer row is the lock. Every writer of these counters takes it,
        // so a reservation and an adjustment cannot interleave.
        $result = DB::transaction(function () use ($validated, $quantity, $orgId, $userId): array {
            $offer = Offer::whereKey($validated['offer_id'])
                ->where('seller_organization_id', $orgId)
                ->lockForUpdate()
                ->first();

            if (!$offer) {
                return ['missing' => true];
            }

            if (in_array($offer->status, [Offer::STATUS_CANCELLED, Offer::STATUS_EXPIRED], true)) {
                return ['closed' => true];
            }

            $previousQuantity = (float) $offer->available_quantity;
            $newAvailable = $previousQuantity + $quantity;
            $newTotal = (float) $offer->total_quantity + $quantity;
            $committed = (float) $offer->reserved_quantity + (float) $offer->sold_quantity;

            if ($newAvailable < 0 || $newTotal < $committed) {
                return [
                    'infeasible' => true,
                    'maximum_reduction' => $previousQuantity,
                ];
            }

            // `forceFill`, not `update`: `available_quantity` and `status` are
            // server-derived and are no longer in `Offer::$fillable`. This is the
            // stock ledger telling the listing what it now holds, which is the
            // one case where that is allowed.
            $offer->forceFill([
                'available_quantity' => $newAvailable,
                'total_quantity'     => $newTotal,
                'status'             => Offer::statusAfterQuantityChange((string) $offer->status, $newAvailable),
            ])->save();

            $movement = InventoryMovement::create([
                'organization_id'    => $orgId,
                'warehouse_id'       => $offer->warehouse_id,
                'product_variant_id' => $offer->product_variant_id,
                'offer_id'           => $offer->id,
                'user_id'            => $userId,
                'type'               => $validated['type'],
                'quantity'           => $quantity,
                'previous_quantity'  => $previousQuantity,
                'new_quantity'       => $newAvailable,
                'reference_type'     => Offer::class,
                'reference_id'       => (string) $offer->id,
                'reason'             => $validated['reason'],
            ]);

            return ['movement' => $movement];
        });

        if ($result['missing'] ?? false) {
            throw new ApiRefused(
                'العرض المحدد غير موجود أو لا ينتمي لمنشأتك',
                'OFFER_NOT_OWNED',
                Response::HTTP_FORBIDDEN
            );
        }

        if ($result['closed'] ?? false) {
            throw new ApiRefused(
                'لا يمكن تسجيل حركة مخزون على عرض منتهٍ أو ملغى',
                'OFFER_CLOSED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($result['infeasible'] ?? false) {
            throw new ApiRefused(
                'التعديل المطلوب يجعل الكميات غير متسقة مع المحجوز أو المبيع',
                'QUANTITY_INFEASIBLE',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['maximum_reduction' => $result['maximum_reduction']]
            );
        }

        return $result['movement'];
    }
}
