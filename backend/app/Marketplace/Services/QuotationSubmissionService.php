<?php

namespace App\Marketplace\Services;

use App\Core\Exceptions\ApiRefused;
use App\Marketplace\Models\Demand;
use App\Marketplace\Models\Quotation;
use App\Platform\Models\Currency;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A seller's answer to one buyer request, from the gate to the stored price.
 *
 * The gate is a method the controller calls before it reads the form, and that
 * order is the substance of this file rather than a detail of it. A demand that
 * has closed and a quotation with no quantity are both refusals, but only one of
 * them is about the seller's typing: a buyer who is watching their own RFQ should
 * not be told their price was invalid when the truth is that the window shut
 * hours ago, and the route answers differently for the two.
 */
class QuotationSubmissionService
{
    /**
     * Whether this demand can still take a quote from this seller at all.
     *
     * @throws ApiRefused with the exact envelope the API answers with
     */
    public function assertQuotable(Demand $demand, int $sellerOrgId): void
    {
        if ($demand->status !== Demand::STATUS_OPEN) {
            throw new ApiRefused(
                'الطلب مغلق ولا يقبل عروض أسعار جديدة',
                'DEMAND_CLOSED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // The buyer quoting their own RFQ would be a seller facing them. The
        // platform does not audit who stands behind an organization id, so this
        // identity check is the whole defence against a business answering its
        // own demand with a price nobody competed with.
        if ((int) $demand->buyer_organization_id === (int) $sellerOrgId) {
            throw new ApiRefused(
                'لا يمكنك تقديم عرض سعر لطلب منشأتك',
                'SELF_QUOTATION_DENIED',
                Response::HTTP_FORBIDDEN
            );
        }
    }

    /**
     * Store the quote: priced in the demand's currency unless the seller names
     * another, totalled by the platform rather than by the caller.
     *
     * @param array<string, mixed> $validated
     */
    public function submit(Demand $demand, array $validated, int $sellerOrgId, int $sellerUserId): Quotation
    {
        // Inheriting the demand's currency is what makes a board of quotes
        // comparable; a seller quoting in a currency the platform has retired
        // would leave the buyer adding up prices that cannot be added.
        $currencyCode = strtoupper($validated['currency_code'] ?? $demand->currency_code);

        if (!Currency::isActive($currencyCode)) {
            throw new ApiRefused(
                'عملة عرض السعر غير موجودة أو غير مفعلة',
                'CURRENCY_NOT_ACTIVE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $quantity = (float) $validated['quoted_quantity'];
        $unitPrice = (float) $validated['unit_price'];

        return Quotation::create([
            'quotation_number'       => 'QUO-' . date('ymd') . '-' . strtoupper(Str::random(6)),
            'demand_id'              => $demand->id,
            'seller_organization_id' => $sellerOrgId,
            'seller_user_id'         => $sellerUserId,
            'quoted_quantity'        => $quantity,
            'unit_price'             => $unitPrice,
            'currency_code'          => $currencyCode,
            // The total is derived, never accepted: a client that rounds
            // differently would put its own arithmetic on the record, and the
            // buyer compares these numbers, not the two fields they came from.
            'total_amount'           => round($quantity * $unitPrice, 4),
            'validity_days'          => $validated['validity_days'] ?? 7,
            'notes'                  => $validated['notes'] ?? null,
            'status'                 => Quotation::STATUS_SUBMITTED,
        ]);
    }
}
