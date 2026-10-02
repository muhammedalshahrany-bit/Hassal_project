<?php

namespace Tests\Feature\Marketplace;

use App\Marketplace\Models\Demand;
use App\Marketplace\Models\DemandMatch;
use App\Marketplace\Models\Offer;
use App\Inventory\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * Matching: rules a buyer can read, not a number they have to trust.
 *
 * §19-§22 are explicit that this engine is rule-based and explainable. So the
 * tests here care about three things: which offers are excluded and why, that
 * every reason states a comparison the data actually supports, and that a match
 * stops being offered the moment it stops being true.
 */
class DemandMatchingTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /**
     * @return array{0: \App\Identity\Models\User, 1: \App\Organizations\Models\Organization}
     */
    protected function buyer()
    {
        return $this->accountWithOrganization('BuyerAdmin', 'buyer', 'بقالة');
    }

    protected function stockedWarehouse(\App\Organizations\Models\Organization $seller, string $city = self::CITY): Warehouse
    {
        return $this->warehouse($seller, [
            'city' => $city,
            'latitude' => $city === self::CITY ? self::LAT : 15.3694444,
            'longitude' => $city === self::CITY ? self::LNG : 44.1910000,
        ]);
    }

    public function test_a_demand_is_answered_only_by_offers_that_fit_the_rules(): void
    {
        [$buyerUser, $buyer] = $this->buyer();
        [, $localSeller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد محلي');
        [, $remoteSeller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد من صنعاء');
        [, $otherSeller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد غير مطابق');

        $category = $this->category();
        $otherCategory = $this->category();

        // A: same category, same city, priced under target — the best answer.
        $local = $this->offer($localSeller, [
            'unit_price' => 24000,
            'min_order_quantity' => 5,
        ], $this->stockedWarehouse($localSeller), $this->productVariant($localSeller, $category));

        // B: same category, another city, 10% above target — still honest to show.
        $remote = $this->offer($remoteSeller, [
            'unit_price' => 27000,
            'min_order_quantity' => 5,
        ], $this->stockedWarehouse($remoteSeller, 'صنعاء'), $this->productVariant($remoteSeller, $category));

        // C: right product, wrong category — the mandatory gate excludes it.
        $this->offer($otherSeller, [], $this->stockedWarehouse($otherSeller), $this->productVariant($otherSeller, $otherCategory));

        // D: in the right category but not on the market.
        $this->offer($localSeller, ['status' => Offer::STATUS_DRAFT, 'published_at' => null], $local->warehouse, $local->variant);
        $this->offer($localSeller, ['status' => Offer::STATUS_PAUSED], $local->warehouse, $local->variant);
        $this->offer($localSeller, ['status' => Offer::STATUS_SOLD_OUT, 'available_quantity' => 0, 'sold_quantity' => 100], $local->warehouse, $local->variant);

        Sanctum::actingAs($buyerUser);

        $demandId = $this->postJson('/api/v1/demands', [
            'title_ar' => 'أحتاج دقيقاً للبقالة',
            'category_id' => $category->id,
            'target_quantity' => 40,
            'unit' => 'carton',
            'target_unit_price' => 25000,
            'delivery_city' => self::CITY,
            'delivery_latitude' => self::LAT,
            'delivery_longitude' => self::LNG,
        ], $this->withTenant($buyer))->assertCreated()->json('data.id');

        $response = $this->getJson('/api/v1/demands/' . $demandId . '/matches', $this->withTenant($buyer))
            ->assertOk();

        $offerIds = array_column($response->json('data.matches'), 'offer_id');

        $this->assertCount(2, $offerIds);
        $this->assertContains($local->id, $offerIds);
        $this->assertContains($remote->id, $offerIds);
        $this->assertSame(2, $response->json('data.total'));

        // Ranking: the local, in-range offer must be presented first.
        $this->assertSame($local->id, $response->json('data.matches.0.offer_id'));
        $this->assertGreaterThanOrEqual(
            $response->json('data.matches.1.match_score'),
            $response->json('data.matches.0.match_score')
        );
    }

    public function test_the_engine_explains_its_answer_in_terms_the_data_supports(): void
    {
        [$buyerUser, $buyer] = $this->buyer();
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مطحنة');

        $category = $this->category();
        $offer = $this->offer($seller, ['unit_price' => 24000, 'min_order_quantity' => 5], $this->stockedWarehouse($seller), $this->productVariant($seller, $category));

        Sanctum::actingAs($buyerUser);
        $demand = $this->demand($buyer, $buyerUser, [
            'category_id' => $category->id,
            'target_quantity' => 40,
            'target_unit_price' => 25000,
        ]);

        $match = $this->getJson('/api/v1/demands/' . $demand->id . '/matches', $this->withTenant($buyer))
            ->assertOk()
            ->json('data.matches.0');

        $this->assertSame($offer->id, $match['offer_id']);

        $reasons = $match['match_reasons'];

        // Every claim the engine is allowed to make, and each one is a
        // comparison a buyer could check by hand.
        $this->assertArrayHasKey('category_match', $reasons);
        $this->assertArrayHasKey('quantity_available', $reasons);
        $this->assertArrayHasKey('price_in_range', $reasons);
        $this->assertArrayHasKey('city_match', $reasons);
        $this->assertArrayHasKey('offer_active', $reasons);

        // Claims it is not allowed to make: a demand never names a product, and
        // a demand carries no delivery preference to be compatible with.
        $this->assertArrayNotHasKey('product_match', $reasons);
        $this->assertArrayNotHasKey('fulfillment_available', $reasons);

        // A bare score is the thing §21 forbids as an answer on its own.
        $this->assertNotEmpty($reasons);
        $this->assertGreaterThan(0, (float) $match['match_score']);
    }

    public function test_an_offer_the_buyer_cannot_actually_be_served_from_is_not_matched(): void
    {
        [$buyerUser, $buyer] = $this->buyer();
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مخزن جملة');
        $category = $this->category();

        // The buyer wants 5 cartons; this seller will not open a line under 20.
        $this->offer($seller, ['min_order_quantity' => 20, 'total_quantity' => 500, 'available_quantity' => 500], $this->stockedWarehouse($seller), $this->productVariant($seller, $category));

        Sanctum::actingAs($buyerUser);
        $demand = $this->demand($buyer, $buyerUser, [
            'category_id' => $category->id,
            'target_quantity' => 5,
        ]);

        $this->getJson('/api/v1/demands/' . $demand->id . '/matches', $this->withTenant($buyer))
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_a_partial_supply_is_offered_as_a_partial_not_as_a_fill(): void
    {
        [$buyerUser, $buyer] = $this->buyer();
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller');
        $category = $this->category();

        $offer = $this->offer($seller, [
            'total_quantity' => 120,
            'available_quantity' => 30,
            'reserved_quantity' => 90,
            'min_order_quantity' => 10,
        ], $this->stockedWarehouse($seller), $this->productVariant($seller, $category));

        Sanctum::actingAs($buyerUser);
        $demand = $this->demand($buyer, $buyerUser, [
            'category_id' => $category->id,
            'target_quantity' => 100,
            'target_unit_price' => null,
        ]);

        $match = $this->getJson('/api/v1/demands/' . $demand->id . '/matches', $this->withTenant($buyer))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->json('data.matches.0');

        $this->assertSame('30.00', (string) $match['requested_quantity']);
        $this->assertArrayHasKey('quantity_partial', $match['match_reasons']);
        $this->assertArrayNotHasKey('quantity_available', $match['match_reasons']);

        // A demand with no price target gets no price claim in return.
        $this->assertArrayNotHasKey('price_in_range', $match['match_reasons']);

        // And the estimate is the seller's own price times what could be served.
        $this->assertSame(
            round(30 * (float) $offer->unit_price, 4),
            round((float) $match['estimated_total'], 4)
        );
    }

    public function test_a_match_that_stops_being_true_is_retired(): void
    {
        [$buyerUser, $buyer] = $this->buyer();
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller');
        $category = $this->category();

        $offer = $this->offer($seller, ['min_order_quantity' => 5], $this->stockedWarehouse($seller), $this->productVariant($seller, $category));

        Sanctum::actingAs($buyerUser);
        $demand = $this->demand($buyer, $buyerUser, ['category_id' => $category->id, 'target_quantity' => 40]);

        $this->getJson('/api/v1/demands/' . $demand->id . '/matches', $this->withTenant($buyer))
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $matchId = DemandMatch::where('demand_id', $demand->id)->where('offer_id', $offer->id)->value('id');

        // Somebody takes the whole shelf. The demand is now unanswerable.
        //
        // `forceFill` and not `update`: these four columns are the ones the
        // model refuses from a request, because a caller that could set
        // `available_quantity` is a caller that can oversell reserved stock. A
        // test that *is* the stock system says so out loud rather than having
        // the keys dropped in silence by the guard.
        $offer->forceFill([
            'available_quantity' => 0,
            'reserved_quantity' => 100,
            'status' => Offer::STATUS_SOLD_OUT,
        ])->save();

        $second = $this->getJson('/api/v1/demands/' . $demand->id . '/matches', $this->withTenant($buyer))
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $this->assertSame(DemandMatch::STATUS_INVALIDATED, DemandMatch::find($matchId)->status);

        // A USED match belongs to a purchase request already: the engine may
        // stop offering it, but it must not rewrite history.
        DB::table('demand_matches')->where('id', $matchId)->update(['status' => DemandMatch::STATUS_USED]);
        $offer->forceFill([
            'available_quantity' => 100,
            'reserved_quantity' => 0,
            'status' => Offer::STATUS_ACTIVE,
        ])->save();

        $this->getJson('/api/v1/demands/' . $demand->id . '/matches', $this->withTenant($buyer))
            ->assertOk()
            ->assertJsonPath('data.total', 0);
        $this->assertSame(DemandMatch::STATUS_USED, DemandMatch::find($matchId)->status);
    }

    public function test_a_demand_can_only_be_read_by_the_business_that_raised_it(): void
    {
        [$buyerUser, $buyer] = $this->buyer();
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller');
        [$strangerUser, $stranger] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'منافس');

        $category = $this->category();
        $this->offer($seller, [], $this->stockedWarehouse($seller), $this->productVariant($seller, $category));

        $demand = $this->demand($buyer, $buyerUser, ['category_id' => $category->id]);

        Sanctum::actingAs($strangerUser);
        $this->getJson('/api/v1/demands/' . $demand->id . '/matches', $this->withTenant($stranger))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'DEMAND_ACCESS_DENIED');

        // The seller whose offer matched is not entitled to see the buyer's ask,
        // its quantity or its target price.
        Sanctum::actingAs($sellerUser);
        $this->getJson('/api/v1/demands/' . $demand->id . '/matches', $this->withTenant($seller))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'DEMAND_ACCESS_DENIED');

        Sanctum::actingAs($buyerUser);
        $this->getJson('/api/v1/demands/' . $demand->id . '/matches', $this->withTenant($buyer))
            ->assertOk();
    }

    public function test_an_expired_demand_stops_being_answered(): void
    {
        [$buyerUser, $buyer] = $this->buyer();
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller');
        $category = $this->category();
        $this->offer($seller, [], $this->stockedWarehouse($seller), $this->productVariant($seller, $category));

        Sanctum::actingAs($buyerUser);
        $demand = $this->demand($buyer, $buyerUser, ['category_id' => $category->id]);

        $this->getJson('/api/v1/demands/' . $demand->id . '/matches', $this->withTenant($buyer))
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $demand->update(['status' => Demand::STATUS_EXPIRED]);

        $this->getJson('/api/v1/demands/' . $demand->id . '/matches', $this->withTenant($buyer))
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_publishing_an_offer_answers_an_open_demand_without_being_asked(): void
    {
        [$buyerUser, $buyer] = $this->buyer();
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $category = $this->category();
        $warehouse = $this->stockedWarehouse($seller);
        $variant = $this->productVariant($seller, $category);

        Sanctum::actingAs($buyerUser);
        $demand = $this->demand($buyer, $buyerUser, ['category_id' => $category->id, 'target_quantity' => 20]);

        // Nothing existed to match yet.
        $this->assertDatabaseCount('demand_matches', 0);

        Sanctum::actingAs($sellerUser);
        $offerId = $this->postJson('/api/v1/marketplace/offers', [
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'title_ar' => 'أرز حبة كاملة',
            'opportunity_reason' => Offer::REASON_SURPLUS_STOCK,
            'min_order_quantity' => 10,
            'total_quantity' => 60,
            'unit' => 'carton',
            'unit_price' => 24500,
        ], $this->withTenant($seller))
            ->assertCreated()
            ->assertJsonPath('data.status', Offer::STATUS_ACTIVE)
            ->json('data.id');

        // Publishing is what made the offer a commercial fact, so it is also the
        // moment the waiting demand gets its answer — written by the engine, not
        // by a client coming back to look.
        $this->assertDatabaseHas('demand_matches', [
            'demand_id' => $demand->id,
            'offer_id' => $offerId,
            'status' => DemandMatch::STATUS_ACTIVE,
            'requested_quantity' => '20.00',
        ]);
    }

    public function test_re_running_the_engine_never_duplicates_a_pair(): void
    {
        [$buyerUser, $buyer] = $this->buyer();
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller');
        $category = $this->category();
        $this->offer($seller, [], $this->stockedWarehouse($seller), $this->productVariant($seller, $category));

        Sanctum::actingAs($buyerUser);
        $demand = $this->demand($buyer, $buyerUser, ['category_id' => $category->id]);

        foreach (range(1, 3) as $ignored) {
            $this->getJson('/api/v1/demands/' . $demand->id . '/matches', $this->withTenant($buyer))->assertOk();
        }

        $this->assertDatabaseCount('demand_matches', 1);
        $this->assertSame(1, DemandMatch::where('demand_id', $demand->id)->where('status', DemandMatch::STATUS_ACTIVE)->count());
    }
}
