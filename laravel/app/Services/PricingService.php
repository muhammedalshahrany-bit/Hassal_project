<?php

namespace App\Services;

class PricingService
{
    /**
     * Calculate financial totals with ZATCA compliant VAT & platform fee.
     * All calculations are strictly rounded to 2 decimal places.
     *
     * @param float $quantity
     * @param float $unitPrice
     * @param float|null $customVatRate
     * @param float|null $customCommissionRate
     * @return array
     */
    public function calculateDealTotals(
        float $quantity,
        float $unitPrice,
        ?float $customVatRate = null,
        ?float $customCommissionRate = null
    ): array {
        $vatRate = $customVatRate ?? (float) config('hassal.vat_rate', 0.15);
        $commissionRate = $customCommissionRate ?? (float) config('hassal.commission_rate', 0.025);

        $subtotal = round($quantity * $unitPrice, 2);
        $vatAmount = round($subtotal * $vatRate, 2);
        $totalAmount = round($subtotal + $vatAmount, 2);

        $platformFee = round($subtotal * $commissionRate, 2);
        $platformFeeVat = round($platformFee * $vatRate, 2);
        $totalPlatformDeduction = round($platformFee + $platformFeeVat, 2);

        $netSellerPayout = round($totalAmount - $totalPlatformDeduction, 2);

        return [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'total_amount' => $totalAmount,
            'commission_rate' => $commissionRate,
            'platform_fee' => $platformFee,
            'platform_fee_vat' => $platformFeeVat,
            'total_platform_fee' => $totalPlatformDeduction,
            'net_seller_payout' => $netSellerPayout,
            'currency' => config('hassal.default_currency', 'SAR'),
        ];
    }
}
