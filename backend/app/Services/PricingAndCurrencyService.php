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
    public function calculateDealPricing(float $quantity, float $unitPrice, ?string $currencyCode = null, ?float $discountAmount = 0.0000, ?float $deliveryFeeAmount = 0.0000): array
    {
        // Resolve the transaction currency first: every figure below is only
        // meaningful in a known currency, and the base-currency figures are
        // derived from the rate that was valid at this moment.
        $baseCurrency = Currency::where('is_default', true)->first();
        $baseCurrencyCode = $baseCurrency ? $baseCurrency->iso_code : null;

        if ($baseCurrencyCode === null) {
            throw new Exception('No base currency is configured. Set one before pricing a transaction.');
        }

        $currencyCode = $currencyCode ?: $baseCurrencyCode;

        $currency = Currency::where('iso_code', $currencyCode)->first();
        if (!$currency || !$currency->is_active) {
            // A missing rate is not a 1:1 rate. Guessing here would put
            // invented arithmetic onto a real commercial document.
            throw new Exception("Currency {$currencyCode} is not available for pricing.");
        }

        $exchangeRate = (float)$currency->exchange_rate_to_base;
        if ($exchangeRate <= 0) {
            throw new Exception("Currency {$currencyCode} has no usable exchange rate.");
        }
        $rateSource = $currency->rate_source ?: 'MANUAL_ADMIN_RATE';

        $lineAmount = round($quantity * $unitPrice, 4);
        $effectiveDiscount = min($lineAmount, max(0.0000, (float)$discountAmount));
        $netSubtotal = round($lineAmount - $effectiveDiscount, 4);

        // Tax and commission are commercial terms owned by the operator. They
        // exist only in the settings store: an absent setting means the
        // platform is not charging for it, never a compiled-in default rate.
        $taxEnabled = (bool) $this->settingsService->get('tax.enabled', false);
        $taxRate = $taxEnabled ? (float) $this->settingsService->get('tax.rate', 0.0000) : 0.0000;
        $taxAmount = round($netSubtotal * $taxRate, 4);

        $commissionRate = (float) $this->settingsService->get('commission.rate', 0.0000);
        $platformFeeAmount = round($netSubtotal * $commissionRate, 4);

        $deliveryFee = max(0.0000, (float)$deliveryFeeAmount);

        $buyerTotalAmount = round($netSubtotal + $taxAmount + $deliveryFee, 4);
        $sellerNetAmount = round($netSubtotal - $platformFeeAmount, 4);

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
            // Kept as the line total in the transaction currency because
            // DealController stores it as the deal subtotal. The converted
            // figure has its own key so the two are never confused.
            'base_amount' => $lineAmount,
            'line_amount' => $lineAmount,
            // `exchange_rate_to_base` is what an administrator entered as
            // "how many of this currency does one unit of the base buy"
            // (base row = 1.0, and a live USD row of 0.004000 against a YER
            // base can only mean that direction). Getting a base-currency
            // figure out of an amount written in this currency is therefore a
            // division: 100 USD / 0.004 = 25,000 YER. Multiplying — which this
            // line used to do — turned that same line into 0.40 YER.
            // Everything else in this array, and every total stored on a deal,
            // stays in the transaction currency; this key is display-only.
            'base_currency_amount' => round($lineAmount / $exchangeRate, 4),
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
}
