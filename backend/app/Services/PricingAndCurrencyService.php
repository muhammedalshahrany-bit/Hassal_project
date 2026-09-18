<?php

namespace App\Services;

use App\Models\Currency;
use Exception;

class PricingAndCurrencyService
{
    public function __construct(
        protected SystemSettingsService $settingsService
    ) {}

    /**
     * Calculates line pricing, taxes, platform commission, and net payouts.
     * All monetary calculations preserve 4 decimal points internally and 2 decimal points for display.
     */
    public function calculateDealPricing(float $quantity, float $unitPrice, string $currencyCode = 'YER', ?float $discountAmount = 0.0000, ?float $deliveryFeeAmount = 0.0000): array
    {
        $baseAmount = round($quantity * $unitPrice, 4);
        $effectiveDiscount = min($baseAmount, max(0.0000, (float)$discountAmount));
        $netSubtotal = round($baseAmount - $effectiveDiscount, 4);

        $taxEnabled = (bool) $this->settingsService->get('tax.enabled', env('TAX_ENABLED', false));
        $taxRate = $taxEnabled ? (float) $this->settingsService->get('tax.rate', env('TAX_RATE', 0.0000)) : 0.0000;
        $taxAmount = round($netSubtotal * $taxRate, 4);

        $commissionRate = (float)$this->settingsService->get('commission.rate', env('PLATFORM_COMMISSION_RATE', 0.0250));
        $platformFeeAmount = round($netSubtotal * $commissionRate, 4);

        $deliveryFee = max(0.0000, (float)$deliveryFeeAmount);

        $buyerTotalAmount = round($netSubtotal + $taxAmount + $deliveryFee, 4);
        $sellerNetAmount = round($netSubtotal - $platformFeeAmount, 4);

        // Fetch database-backed currency exchange rate
        $baseCurrency = Currency::where('is_default', true)->first();
        $baseCurrencyCode = $baseCurrency ? $baseCurrency->iso_code : 'YER';

        $currency = Currency::where('iso_code', $currencyCode)->where('is_active', true)->first();
        $exchangeRate = $currency ? (float)$currency->exchange_rate_to_base : 1.000000;
        $rateSource = 'MANUAL_ADMIN_RATE';

        return [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'currency_code' => $currencyCode,
            'transaction_currency' => $currencyCode,
            'base_currency' => $baseCurrencyCode,
            'exchange_rate' => $exchangeRate,
            'exchange_rate_snapshot' => $exchangeRate,
            'rate_source' => $rateSource,
            'rate_timestamp' => now(),
            'base_amount' => $baseAmount,
            'discount_amount' => $effectiveDiscount,
            'net_subtotal' => $netSubtotal,
            'tax_rate_snapshot' => $taxRate,
            'tax_amount' => $taxAmount,
            'platform_fee_rate_snapshot' => $commissionRate,
            'platform_fee_amount' => $platformFeeAmount,
            'delivery_fee_amount' => $deliveryFee,
            'buyer_total_amount' => $buyerTotalAmount,
            'seller_net_amount' => $sellerNetAmount,
        ];
    }

    /**
     * Converts an amount from source currency to target currency using base exchange rates.
     */
    public function convert(float $amount, string $fromCurrency, string $toCurrency = 'YER'): float
    {
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        $from = Currency::where('iso_code', $fromCurrency)->first();
        $to = Currency::where('iso_code', $toCurrency)->first();

        if (!$from || !$to) {
            throw new Exception("Unsupported currency conversion from {$fromCurrency} to {$toCurrency}");
        }

        // Convert from source to the database-selected base currency.
        $amountInBase = $amount * (float)$from->exchange_rate_to_base;

        // Convert Base to target
        $targetRate = (float)$to->exchange_rate_to_base;
        if ($targetRate <= 0) {
            throw new Exception("Invalid exchange rate for currency {$toCurrency}");
        }

        return round($amountInBase / $targetRate, 4);
    }
}
