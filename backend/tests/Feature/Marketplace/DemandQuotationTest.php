<?php

namespace Tests\Feature\Marketplace;

use App\Marketplace\Models\Demand;
use App\Marketplace\Models\Quotation;
use App\Platform\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * Publishing a buyer request, and a seller answering it.
 *
 * The two decisions worth testing here are the ones a client cannot see: what a
 * demand is priced in and how long it lives when the buyer says neither, and what
 * a quotation costs when only the two factors were sent. The rest of the file is
 * about order — a demand that cannot be answered is refused for *that* reason,
 * ahead of any complaint about the form, because the two messages tell completely
 * different stories to the person standing at the gate.
 */
class DemandQuotationTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /**
     * @return array<string, mixed> the smallest form the route accepts
     */
    protected function ask(array $overrides = []): array
    {
        return array_merge([
            'title_ar'        => 'أحتاج أرزاً للبقالة',
            'target_quantity' => 40,
            'unit'            => 'carton',
            'delivery_city'   => self::CITY,
        ], $overrides);
    }

    public function test_a_request_with_no_currency_and_no_date_takes_the_platforms_own_both(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'بقالة');

        // Notice period moved by an operator, so the test proves the demand read
        // the switch rather than a constant that happens to say 14.
        $this->setSetting('demand.expiry_days', '30', 'integer');

        Sanctum::actingAs($buyerUser);

        $demandId = $this->postJson('/api/v1/demands', $this->ask(), $this->withTenant($buyer))
            ->assertCreated()
            ->assertJsonPath('data.status', Demand::STATUS_OPEN)
            ->json('data.id');

        $demand = Demand::find($demandId);

        $this->assertSame('YER', $demand->currency_code);
        $this->assertStringStartsWith('RFQ-', $demand->demand_number);
        $this->assertTrue($demand->expires_at->between(now()->addDays(29), now()->addDays(31)));
    }

    public function test_a_request_priced_in_a_currency_the_platform_retired_is_refused_and_never_stored(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'بقالة');

        Currency::create([
            'iso_code' => 'SAR', 'name_ar' => 'ريال سعودي', 'name_en' => 'Saudi Riyal',
            'symbol' => 'ر.س', 'decimal_places' => 2, 'exchange_rate_to_base' => 0.055,
            'rate_source' => 'MANUAL', 'is_active' => false, 'is_default' => false,
        ]);

        Sanctum::actingAs($buyerUser);

        $this->postJson('/api/v1/demands', $this->ask(['currency_code' => 'sar']), $this->withTenant($buyer))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CURRENCY_NOT_ACTIVE')
            ->assertJsonPath('error.message', 'العملة المحددة غير موجودة أو غير مفعلة');

        // A row that exists but is switched off is the case that matters: the
        // currency is in the table, and only the active half of the check knows
        // it may not hold money. Nothing was written on the way out, either —
        // a demand nobody can quote is a demand the buyer has to delete by hand.
        $this->assertDatabaseCount('demands', 0);
    }

    public function test_a_quote_states_only_two_numbers_and_the_platform_computes_the_third(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'بقالة');
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $demand = $this->demand($buyer, $buyerUser, ['currency_code' => 'YER']);

        Sanctum::actingAs($sellerUser);

        $quotationId = $this->postJson('/api/v1/demands/' . $demand->id . '/quotations', [
            'quoted_quantity' => 12.5,
            'unit_price'      => 24000,
        ], $this->withTenant($seller))
            ->assertCreated()
            ->assertJsonPath('data.status', Quotation::STATUS_SUBMITTED)
            ->json('data.id');

        $quotation = Quotation::find($quotationId);

        $this->assertStringStartsWith('QUO-', $quotation->quotation_number);
        $this->assertSame('300000.0000', (string) $quotation->total_amount);
        $this->assertSame('YER', $quotation->currency_code);
        $this->assertSame(7, $quotation->validity_days);

        // The quote belongs to the seller who sent it, not to the organization
        // the client claimed in any other field.
        $this->assertSame($seller->id, $quotation->seller_organization_id);
        $this->assertSame($sellerUser->id, $quotation->seller_user_id);
    }

    public function test_a_closed_request_is_refused_before_the_form_is_read(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'بقالة');
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $demand = $this->demand($buyer, $buyerUser);
        $demand->update(['status' => Demand::STATUS_CLOSED]);

        Sanctum::actingAs($sellerUser);

        // An empty body would fail validation on its own; the caller must still
        // hear that the window shut, because that is the sentence that ends the
        // conversation rather than one that restarts it.
        $this->postJson('/api/v1/demands/' . $demand->id . '/quotations', [], $this->withTenant($seller))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'DEMAND_CLOSED');
    }

    public function test_a_business_cannot_answer_its_own_request(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'بقالة');

        $demand = $this->demand($buyer, $buyerUser);

        Sanctum::actingAs($buyerUser);

        // Complete and arithmetically fine: the refusal is about who is on both
        // sides of it, so it is a 403 and not a complaint about the numbers.
        $this->postJson('/api/v1/demands/' . $demand->id . '/quotations', [
            'quoted_quantity' => 40,
            'unit_price'      => 25000,
        ], $this->withTenant($buyer))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'SELF_QUOTATION_DENIED');

        $this->assertDatabaseCount('quotations', 0);
    }

    public function test_a_quote_in_a_retired_currency_is_refused_in_the_words_the_seller_used(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'بقالة');
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        Currency::create([
            'iso_code' => 'USD', 'name_ar' => 'دولار', 'name_en' => 'US Dollar',
            'symbol' => '$', 'decimal_places' => 2, 'exchange_rate_to_base' => 0.0042,
            'rate_source' => 'MANUAL', 'is_active' => false, 'is_default' => false,
        ]);

        $demand = $this->demand($buyer, $buyerUser);

        Sanctum::actingAs($sellerUser);

        $this->postJson('/api/v1/demands/' . $demand->id . '/quotations', [
            'quoted_quantity' => 10,
            'unit_price'      => 5,
            'currency_code'   => 'USD',
        ], $this->withTenant($seller))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CURRENCY_NOT_ACTIVE')
            ->assertJsonPath('error.message', 'عملة عرض السعر غير موجودة أو غير مفعلة');
    }

    public function test_a_request_without_a_business_behind_it_never_reaches_the_board(): void
    {
        $lonely = $this->user('مستخدم بلا منشأة');

        Sanctum::actingAs($lonely);

        // The capability gate stands in front of this route and needs a company
        // to read the capability from, so it is the one that answers — the
        // controller's own "no active organization" guard behind it is
        // defence in depth for a caller that arrives another way. Either way the
        // point of the test is the row that is missing afterwards.
        $this->postJson('/api/v1/demands', $this->ask())
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PERMISSION_DENIED');

        $this->assertDatabaseCount('demands', 0);
    }
}
