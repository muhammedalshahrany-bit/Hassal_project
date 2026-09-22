<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Organization;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * What the platform tells a business about its own money.
 *
 * A report is the one screen where a wrong number becomes a wrong decision, so
 * every assertion here is about a total that could plausibly be printed:
 * turnover counts settled deals only, a period filter moves the totals instead
 * of decorating them, an export says which period it covers, and a seller never
 * sees another seller's volume. §47 applies to a report the same way it applies
 * to a claim: the currency on a financial document is the one the operator
 * configured, or nothing.
 */
class ReportsAndExportTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /**
     * One closed trade: a seller, a buyer, and a deal between them.
     *
     * @return array<string, Deal|Organization|User|\App\Models\Offer>
     */
    protected function trade(string $sellerName = 'شركة البيع'): array
    {
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', $sellerName);
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'شركة الشراء');

        $offer = $this->offer($seller);
        $deal = $this->deal($offer, $buyer, $buyerUser, [
            'seller_user_id' => $sellerUser->id,
            'platform_fee_rate_snapshot' => 0.0100,
        ]);

        return compact('sellerUser', 'seller', 'buyerUser', 'buyer', 'offer', 'deal');
    }

    /** Another deal on the same trade, so totals are a sum of known amounts. */
    protected function anotherDeal(array $trade, array $attributes = []): Deal
    {
        return $this->deal($trade['offer'], $trade['buyer'], $trade['buyerUser'], $attributes);
    }

    public function test_turnover_counts_settled_states_and_labels_its_basis(): void
    {
        $trade = $this->trade();

        // An accepted deal whose goods never moved is not merchandise value.
        $agreed = $this->anotherDeal($trade, [
            'status' => Deal::STATUS_ACCEPTED,
            'quantity' => 40.00,
        ]);

        Sanctum::actingAs($trade['sellerUser']);

        $body = $this->getJson('/api/v1/reports/sales-summary', $this->withTenant($trade['seller']))
            ->assertOk()
            ->assertJsonPath('data.counts_completed_and_settled_only', true)
            ->json('data');

        $this->assertSame(2, $body['total_deals_count'], 'the accepted deal is counted, never totalled');
        $this->assertSame(1, $body['completed_deals_count']);
        $this->assertLessThan((float) $agreed->buyer_total_amount, (float) $body['gross_merchandise_value']);

        // The money is arithmetic the reader can check: 10 × 25,000 at 1%.
        $this->assertSame(250000.0, (float) $body['gross_merchandise_value']);
        $this->assertSame(2500.0, (float) $body['platform_commission_collected']);
    }

    public function test_a_period_window_moves_the_totals_and_names_itself(): void
    {
        $trade = $this->trade();
        $trade['deal']->forceFill(['created_at' => now()->subDays(40)])->save();
        $this->anotherDeal($trade, ['quantity' => 4.00]);

        Sanctum::actingAs($trade['sellerUser']);
        $headers = $this->withTenant($trade['seller']);

        // The old deal is inside a window that ends before the new one exists.
        $from = now()->subDays(45)->toDateString();
        $to = now()->subDays(30)->toDateString();

        $windowed = $this->getJson('/api/v1/reports/sales-summary?from=' . $from . '&to=' . $to, $headers)
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $windowed['total_deals_count']);
        $this->assertSame(250000.0, (float) $windowed['gross_merchandise_value']);
        $this->assertSame($from, $windowed['period_from']);
        $this->assertSame($to, $windowed['period_to']);
        $this->assertSame('created_at', $windowed['period_field']);

        // The other window reports the other deal, so the filter is applied and
        // not merely echoed back.
        $recent = $this->getJson('/api/v1/reports/sales-summary?from=' . now()->subDay()->toDateString()
            . '&to=' . now()->addDay()->toDateString(), $headers)->assertOk()->json('data');

        $this->assertSame(1, $recent['total_deals_count']);
        $this->assertSame(100000.0, (float) $recent['gross_merchandise_value']);

        // With no window the answer still says it covers everything.
        $all = $this->getJson('/api/v1/reports/sales-summary', $headers)->assertOk()->json('data');
        $this->assertSame(2, $all['total_deals_count']);
        $this->assertNull($all['period_from']);
        $this->assertSame(350000.0, (float) $all['gross_merchandise_value']);

        // A nonsense date is refused rather than quietly ignored.
        $this->assertApiValidationErrors(
            $this->getJson('/api/v1/reports/sales-summary?from=not-a-date', $headers),
            ['from']
        );
    }

    public function test_a_business_sees_its_own_volume_and_not_the_markets(): void
    {
        $first = $this->trade('البائع الأول');
        $second = $this->trade('البائع الثاني');
        $this->assertNotSame($first['seller']->id, $second['seller']->id);

        Sanctum::actingAs($first['sellerUser']);
        $mine = $this->getJson('/api/v1/reports/sales-summary', $this->withTenant($first['seller']))
            ->assertOk()
            ->json('data');
        $this->assertSame(1, $mine['total_deals_count']);
        $this->assertSame(250000.0, (float) $mine['gross_merchandise_value']);

        // The buyer side of the same deal is that one deal, not two, and it
        // cannot read its counterparty's other business.
        Sanctum::actingAs($second['buyerUser']);
        $asBuyer = $this->getJson('/api/v1/reports/sales-summary', $this->withTenant($second['buyer']))
            ->assertOk()
            ->json('data');
        $this->assertSame(1, $asBuyer['total_deals_count']);

        // A buyer who never traded reports zero, not the market.
        [$strangerUser, $stranger] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'مشترٍ بلا صفقات');
        Sanctum::actingAs($strangerUser);
        $empty = $this->getJson('/api/v1/reports/sales-summary', $this->withTenant($stranger))
            ->assertOk()
            ->json('data');
        $this->assertSame(0, $empty['total_deals_count']);
        $this->assertSame(0.0, (float) $empty['gross_merchandise_value']);

        // The platform is the only actor that sees the whole market.
        [$platformUser] = $this->accountWithOrganization('SuperAdmin', 'seller', 'منصة حصّال');
        Sanctum::actingAs($platformUser);
        $all = $this->getJson('/api/v1/reports/sales-summary')->assertOk()->json('data');
        $this->assertSame(2, $all['total_deals_count']);
    }

    public function test_a_report_prints_the_configured_currency_or_none(): void
    {
        $trade = $this->trade();
        Sanctum::actingAs($trade['sellerUser']);
        $headers = $this->withTenant($trade['seller']);

        $this->assertSame('YER', $this->getJson('/api/v1/reports/sales-summary', $headers)
            ->assertOk()->json('data.currency'));

        // An operator who has not chosen a base currency gets a bare number,
        // because a symbol on a financial report is a claim, not a default.
        SystemSetting::where('key', 'platform.default_currency')->delete();
        Cache::flush();

        $this->assertNull($this->getJson('/api/v1/reports/sales-summary', $headers)
            ->assertOk()->json('data.currency'));
    }

    public function test_the_deals_export_honours_its_window_and_says_so(): void
    {
        $trade = $this->trade();
        $trade['deal']->forceFill(['created_at' => now()->subDays(40)])->save();
        $recent = $this->anotherDeal($trade);

        Sanctum::actingAs($trade['sellerUser']);
        $headers = $this->withTenant($trade['seller']);

        // The window the client puts on the link is the window the file holds.
        $windowed = $this->get('/api/v1/reports/export/deals-csv?from='
            . now()->subDays(45)->toDateString() . '&to=' . now()->subDays(30)->toDateString(), $headers)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('Period from', $windowed);
        $this->assertStringContainsString($trade['deal']->deal_number, $windowed);
        $this->assertStringNotContainsString($recent->deal_number, $windowed);
        $this->assertSame(1, substr_count($windowed, 'DL-'));

        // Without a window both deals are exported and no period row appears.
        $all = $this->get('/api/v1/reports/export/deals-csv', $headers)->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Period from', $all);
        $this->assertSame(2, substr_count($all, 'DL-'));
        $this->assertStringContainsString($recent->deal_number, $all);

        // The scope is the caller's own side of the ledger: the buyer's export
        // holds the two deals it signed.
        Sanctum::actingAs($trade['buyerUser']);
        $buyerCsv = $this->get('/api/v1/reports/export/deals-csv', $this->withTenant($trade['buyer']))
            ->assertOk()->streamedContent();
        $this->assertSame(2, substr_count($buyerCsv, 'DL-'));
        $this->assertStringContainsString($trade['deal']->deal_number, $buyerCsv);

        // A nonsense date answers as JSON before any byte of CSV.
        Sanctum::actingAs($trade['sellerUser']);
        $this->assertApiValidationErrors(
            $this->getJson('/api/v1/reports/export/deals-csv?from=not-a-date', $headers),
            ['from']
        );
    }

    public function test_a_staff_account_without_the_reporting_permission_is_refused(): void
    {
        $trade = $this->trade();
        $staffOrg = $this->organization('seller', 'فروع البيع');

        // Not an owner: `accountWithOrganization()` makes its account the owner
        // of the company it creates, and an owner holds every business grant —
        // so the account that is meant to lack one has to be built as the
        // colleague it is describing, with a staff membership and nothing else.
        $staffUser = $this->memberOf($staffOrg, 'staff');

        Sanctum::actingAs($staffUser);

        // §36: the right is checked by the route, not by a hidden tab.
        $this->getJson('/api/v1/reports/sales-summary', $this->withTenant($staffOrg))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PERMISSION_DENIED');

        $this->get('/api/v1/reports/export/deals-csv', $this->withTenant($staffOrg))
            ->assertStatus(403);
    }
}
