<?php

namespace Tests\Feature;

use App\Services\PricingAndCurrencyService;
use Tests\TestCase;

class PricingAndCurrencyTest extends TestCase
{
    protected PricingAndCurrencyService $pricingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingService = app(PricingAndCurrencyService::class);
    }

    public function test_calculate_deal_financials_with_vat_and_commission(): void
    {
        $quantity = 100.00;
        $unitPrice = 50.00; // Subtotal = 5000.00 SAR

        $financials = $this->pricingService->calculateDealFinancials($quantity, $unitPrice, 'SAR');

        $this->assertEquals(5000.00, $financials['subtotal_amount']);
        $this->assertEquals(0.15, $financials['vat_rate']);
        $this->assertEquals(750.00, $financials['vat_amount']); // 15% of 5000
        $this->assertEquals(0.025, $financials['platform_commission_rate']);
        $this->assertEquals(125.00, $financials['platform_fee_amount']); // 2.5% of 5000
        $this->assertEquals(5750.00, $financials['buyer_total_amount']); // 5000 + 750
        $this->assertEquals(4875.00, $financials['seller_net_amount']); // 5000 - 125
        $this->assertEquals('SAR', $financials['currency_code']);
    }

    public function test_currency_conversion_to_base_and_target(): void
    {
        // 100 USD at 3.75 SAR/USD = 375 SAR
        $sarAmount = $this->pricingService->convertToBase(100.00, 'USD');
        $this->assertEquals(375.00, $sarAmount);

        // 375 SAR back to USD = 100 USD
        $usdAmount = $this->pricingService->convertFromBase(375.00, 'USD');
        $this->assertEquals(100.00, $usdAmount);
    }
}
