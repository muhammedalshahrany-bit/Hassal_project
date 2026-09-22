<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Services\PricingAndCurrencyService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * What a line item is worth, and in which currency.
 *
 * `exchange_rate_to_base` is operator data entered as "how many units of this
 * currency buy one unit of the base" — 0.004 on USD means one rial buys 0.004
 * dollars. Converting a transaction amount into base therefore *divides*. Until
 * this test existed, the service multiplied, so a 100 USD line printed as
 * 0.40 YER instead of 25,000: an inverted figure on a commercial document,
 * uncovered by any test because no test touched pricing at all.
 *
 * The rest of this file pins the two rules the master specification insists on:
 * an unknown rate is a refusal rather than a guess, and tax and commission
 * exist only as settings — an absent setting is zero, never a compiled-in rate.
 */
class PricingAndCurrencyTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected PricingAndCurrencyService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
        $this->pricing = app(PricingAndCurrencyService::class);

        // The rates the live database carries, restated here so the assertion
        // below is arithmetic the reader can check, not a magic number.
        Currency::create([
            'iso_code' => 'USD',
            'name_ar' => 'دولار أمريكي',
            'name_en' => 'US Dollar',
            'symbol' => '$',
            'decimal_places' => 2,
            'exchange_rate_to_base' => 0.004000,
            'rate_source' => 'MANUAL_ADMIN_RATE',
            'is_active' => true,
        ]);
    }

    public function test_a_foreign_amount_is_converted_by_dividing_by_the_rate(): void
    {
        $result = $this->pricing->calculateDealPricing(100.0, 1.0, 'USD');

        $this->assertSame(100.0000, $result['line_amount'], 'The line total stays in the transaction currency.');
        $this->assertSame('USD', $result['currency_code']);
        $this->assertSame('YER', $result['base_currency']);
        $this->assertSame(0.004000, $result['exchange_rate']);

        // 100 USD at "0.004 USD per 1 YER" is 25,000 YER.
        $this->assertSame(
            25000.0000,
            $result['base_currency_amount'],
            'The base-currency figure inverted the operator\'s rate convention.'
        );
    }

    public function test_the_base_currency_converts_to_itself_at_one(): void
    {
        $result = $this->pricing->calculateDealPricing(3.0, 250.0, 'YER');

        $this->assertSame(750.0000, $result['line_amount']);
        $this->assertSame(750.0000, $result['base_currency_amount']);
        $this->assertSame(1.000000, $result['exchange_rate']);
    }

    public function test_base_amount_stays_the_line_total_because_a_deal_stores_it_that_way(): void
    {
        $result = $this->pricing->calculateDealPricing(2.0, 99.0, 'USD');

        // DealController writes `base_amount` as the deal subtotal, so it must
        // remain the transaction-currency line total. The converted figure has
        // its own key; conflating them would restate every stored deal.
        $this->assertSame(198.0000, $result['base_amount']);
        $this->assertSame(198.0000, $result['line_amount']);
        $this->assertSame(49500.0000, $result['base_currency_amount']);
    }

    public function test_an_unavailable_currency_is_refused_instead_of_priced_at_par(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('not available for pricing');

        $this->pricing->calculateDealPricing(1.0, 10.0, 'SAR');
    }

    public function test_an_inactive_currency_is_refused_even_though_it_is_still_listed(): void
    {
        Currency::create([
            'iso_code' => 'AED',
            'name_ar' => 'درهم',
            'name_en' => 'UAE Dirham',
            'symbol' => 'د.إ',
            'decimal_places' => 2,
            'exchange_rate_to_base' => 0.014700,
            'rate_source' => 'MANUAL_ADMIN_RATE',
            'is_active' => false,
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('not available for pricing');

        $this->pricing->calculateDealPricing(1.0, 10.0, 'AED');
    }

    public function test_a_zero_rate_is_refused_rather_than_treated_as_a_free_conversion(): void
    {
        Currency::where('iso_code', 'USD')->update(['exchange_rate_to_base' => 0]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('no usable exchange rate');

        $this->pricing->calculateDealPricing(1.0, 10.0, 'USD');
    }

    public function test_pricing_refuses_to_run_without_a_configured_base_currency(): void
    {
        Currency::where('iso_code', 'YER')->update(['is_default' => false]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No base currency is configured');

        $this->pricing->calculateDealPricing(1.0, 10.0, 'YER');
    }

    public function test_no_tax_and_no_commission_are_charged_until_the_operator_says_so(): void
    {
        $result = $this->pricing->calculateDealPricing(10.0, 100.0, 'YER');

        // The catalog ships tax.enabled off and both rates at zero. A nonzero
        // figure here would mean a rate compiled into the code — a VAT the
        // platform invented.
        $this->assertSame(0.0000, $result['tax_amount']);
        $this->assertSame(0.0000, $result['tax_rate_snapshot']);
        $this->assertSame(0.0000, $result['platform_fee_amount']);
        $this->assertSame(1000.0000, $result['buyer_total_amount']);
        $this->assertSame(1000.0000, $result['seller_net_amount']);
    }

    public function test_tax_and_commission_come_from_the_settings_and_split_the_amount_as_declared(): void
    {
        $this->setSetting('tax.enabled', '1', 'boolean');
        $this->setSetting('tax.rate', '0.0500', 'decimal');
        $this->setSetting('commission.rate', '0.0200', 'decimal');

        $result = $this->pricing->calculateDealPricing(10.0, 100.0, 'YER', 100.0, 50.0);

        $this->assertSame(900.0000, $result['net_subtotal'], 'The discount did not reduce the taxable base.');
        $this->assertSame(45.0000, $result['tax_amount']);
        $this->assertSame(18.0000, $result['platform_fee_amount']);
        $this->assertSame(995.0000, $result['buyer_total_amount'], 'The buyer pays the net, the tax, and the delivery fee.');
        $this->assertSame(882.0000, $result['seller_net_amount'], 'The seller keeps the net less the platform fee.');

        // The rates are snapshotted so a later operator change cannot rewrite
        // a document already agreed on.
        $this->assertSame(0.0500, $result['tax_rate_snapshot']);
        $this->assertSame(0.0200, $result['platform_fee_rate_snapshot']);
    }

    public function test_a_discount_bigger_than_the_line_and_a_negative_fee_are_both_clamped(): void
    {
        $result = $this->pricing->calculateDealPricing(2.0, 10.0, 'YER', 500.0, -7.5);

        $this->assertSame(20.0000, $result['discount_amount'], 'A discount may not exceed the line it discounts.');
        $this->assertSame(0.0000, $result['net_subtotal']);
        $this->assertSame(0.0000, $result['delivery_fee_amount'], 'A negative fee is not a rebate.');
        $this->assertSame(0.0000, $result['buyer_total_amount']);
    }

    public function test_an_omitted_currency_is_the_base_currency_and_not_an_error(): void
    {
        $result = $this->pricing->calculateDealPricing(1.0, 10.0);

        $this->assertSame('YER', $result['currency_code']);
        $this->assertSame('YER', $result['base_currency']);
        $this->assertSame('BASE_CURRENCY', $result['rate_source']);
    }
}
