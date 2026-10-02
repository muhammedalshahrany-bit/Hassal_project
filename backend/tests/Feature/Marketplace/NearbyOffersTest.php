<?php

namespace Tests\Feature\Marketplace;

use App\Marketplace\Models\Offer;
use App\Inventory\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * What the distance search is allowed to put in front of a buyer.
 *
 * `GET /marketplace/offers/nearby` is a second listing path over the same table
 * as the market's index, and a second path is a second chance to forget a rule.
 * The rule it must not forget is the expiry date: nothing in this deployment
 * turns an offer's `status` into EXPIRED when `expires_at` passes — no scheduler
 * does it, so the row keeps saying ACTIVE — which leaves the date as the only
 * record of a listing having ended.
 *
 * The index has carried that bound in its opening chain from the start. The
 * nearby query carries it too, in the filters block below its distance
 * selection — which is precisely why it needed a test: a rule written forty
 * lines after the ones it belongs with is a rule the next reader can miss and
 * "tidy away". These three cases are the first coverage this endpoint has, and
 * they pin the date, the open-ended listing that must survive it, and the radius
 * that must not widen when the date filter is touched.
 */
class NearbyOffersTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
        $this->setFlag('geo_discovery', true);
    }

    /**
     * @return array{0: \App\Organizations\Models\Organization, 1: Warehouse}
     */
    private function shelf(): array
    {
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مزرعة');
        return [$seller, $this->warehouse($seller)];
    }

    private function nearby($center = ['lat' => 12.7855, 'lng' => 45.0187]): \Illuminate\Testing\TestResponse
    {
        return $this->getJson(
            '/api/v1/marketplace/offers/nearby'
            . '?latitude=' . $center['lat'] . '&longitude=' . $center['lng'] . '&radius_km=50'
        );
    }

    public function test_a_listing_past_its_own_expiry_date_is_not_nearby(): void
    {
        [$seller, $warehouse] = $this->shelf();

        $live = $this->offer($seller, [
            'title_ar' => 'سكر حي',
            'expires_at' => now()->addDay(),
        ], $warehouse);
        $over = $this->offer($seller, [
            'title_ar' => 'سكر منتهٍ',
            'expires_at' => now()->subMinute(),
        ], $warehouse);

        // Both rows are ACTIVE on the database, in stock, published, and on the
        // same shelf a few kilometres from the search — so the only fact that
        // separates them is the date, which is exactly what is being tested.
        $this->assertSame(Offer::STATUS_ACTIVE, $over->status);

        $ids = array_column($this->nearby()->json('data.offers'), 'id');

        $this->assertContains($live->id, $ids, 'A live listing beside the searcher must rank.');
        $this->assertNotContains($over->id, $ids, 'A listing past its expiry must not, either.');
    }

    public function test_a_listing_with_no_expiry_date_stays_nearby(): void
    {
        [$seller, $warehouse] = $this->shelf();

        $open = $this->offer($seller, [
            'title_ar' => 'ذرة بلا تاريخ انتهاء',
            'expires_at' => null,
        ], $warehouse);

        // The clause is `null OR future`, not `future`: an offer published
        // without a deadline has not ended, and reading the absence as an expiry
        // would delete every open-ended listing from the map.
        $ids = array_column($this->nearby()->json('data.offers'), 'id');

        $this->assertContains($open->id, $ids);
    }

    public function test_the_distance_bound_still_applies_alongside_the_date(): void
    {
        [$seller, $warehouse] = $this->shelf();
        $far = $this->warehouse($seller, ['latitude' => 15.5500, 'longitude' => 48.5100]);

        $near = $this->offer($seller, ['title_ar' => 'قريب'], $warehouse);
        $away = $this->offer($seller, ['title_ar' => 'بعيد', 'expires_at' => now()->addWeek()], $far);

        $ids = array_column($this->nearby()->json('data.offers'), 'id');

        $this->assertContains($near->id, $ids);
        $this->assertNotContains($away->id, $ids, 'Adding a date filter must not widen the radius.');
    }
}
