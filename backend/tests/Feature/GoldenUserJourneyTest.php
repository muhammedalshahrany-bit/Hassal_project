<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Offer;
use App\Models\PurchaseRequest;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * The product's main road, end to end, over HTTP.
 *
 * §74 says the golden journey must actually work: search -> offer -> location
 * -> purchase request -> seller answers -> stock is held -> deal. This test is
 * the honest version of that claim. It walks the real endpoints with two real
 * organizations and asserts the commercial consequence of every step, because
 * a flow that "returns 200" while nothing is held and nothing is traceable is
 * exactly the kind of product that looks finished and is not.
 */
class GoldenUserJourneyTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    public function test_a_buyer_finds_an_offer_and_reaches_a_deal_through_the_seller(): void
    {
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد جملة');
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'بقال');

        $warehouse = $this->warehouse($seller);
        $category = $this->category();
        $variant = $this->productVariant($seller, $category);

        // 1. The seller publishes an offer from their own warehouse.
        Sanctum::actingAs($sellerUser);
        $offerResponse = $this->postJson('/api/v1/marketplace/offers', [
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'title_ar' => 'أرز بسماتي عبوة ٢٥ كجم',
            'opportunity_reason' => Offer::REASON_SURPLUS_STOCK,
            'min_order_quantity' => 5,
            'total_quantity' => 100,
            'unit' => 'carton',
            'unit_price' => 25000,
            'currency_code' => 'YER',
        ], $this->withTenant($seller));

        $offerResponse->assertCreated()
            ->assertJsonPath('data.status', Offer::STATUS_ACTIVE)
            ->assertJsonPath('data.available_quantity', '100.00');

        $offerId = $offerResponse->json('data.id');

        // 2. A buyer searching their city finds it through the public listing.
        $this->getJson('/api/v1/marketplace/offers?city=' . rawurlencode(self::CITY))
            ->assertOk()
            ->assertJsonPath('data.0.id', $offerId);

        // 3. The buyer raises a need; the engine explains why this offer fits.
        Sanctum::actingAs($buyerUser);

        $demandResponse = $this->postJson('/api/v1/demands', [
            'title_ar' => 'أحتاج أرزاً لعبوات ٢٥ كجم',
            'category_id' => $category->id,
            'target_quantity' => 40,
            'unit' => 'carton',
            'target_unit_price' => 26000,
            'currency_code' => 'YER',
            'delivery_city' => self::CITY,
            'delivery_latitude' => self::LAT,
            'delivery_longitude' => self::LNG,
        ], $this->withTenant($buyer));

        $demandResponse->assertCreated();
        $demandId = $demandResponse->json('data.id');

        $matchesResponse = $this->getJson('/api/v1/demands/' . $demandId . '/matches', $this->withTenant($buyer))
            ->assertOk();

        $this->assertSame(1, $matchesResponse->json('data.total'));
        $match = $matchesResponse->json('data.matches.0');

        $this->assertSame($offerId, $match['offer_id']);
        // An explainable match: reasons are statements about the rules, not a
        // bare score the buyer cannot interrogate.
        $this->assertNotEmpty($match['match_reasons']);
        $this->assertArrayHasKey('quantity_available', $match['match_reasons']);

        // 4. Choosing the offer makes a purchase request — not a deal.
        $requestResponse = $this->postJson('/api/v1/purchase-requests/matches/' . $match['id'], [
            'requested_quantity' => 40,
            'delivery_preference' => 'PICKUP',
        ], $this->withTenant($buyer));

        $requestResponse->assertCreated()
            ->assertJsonPath('data.status', PurchaseRequest::STATUS_REQUESTED);

        $purchaseRequestId = $requestResponse->json('data.id');

        // Asking is not buying: nothing is held yet.
        $offer = Offer::find($offerId);
        $this->assertSame(100.0, (float) $offer->available_quantity);
        $this->assertSame(0.0, (float) $offer->reserved_quantity);
        $this->assertDatabaseCount('deals', 0);

        // 5. The seller accepts, and acceptance is what puts stock on hold.
        Sanctum::actingAs($sellerUser);
        $this->postJson('/api/v1/purchase-requests/' . $purchaseRequestId . '/accept', [], $this->withTenant($seller))
            ->assertOk()
            ->assertJsonPath('data.status', PurchaseRequest::STATUS_ACCEPTED);

        $purchaseRequest = PurchaseRequest::with('reservation')->find($purchaseRequestId);
        $this->assertNotNull($purchaseRequest->reservation_id);
        $this->assertSame(40.0, (float) $purchaseRequest->reservation->quantity);

        $offer->refresh();
        $this->assertSame(60.0, (float) $offer->available_quantity);
        $this->assertSame(40.0, (float) $offer->reserved_quantity);

        // 6. The deal forms against that held stock and remembers the ask.
        Sanctum::actingAs($buyerUser);

        $dealResponse = $this->postJson('/api/v1/deals', [
            'reservation_id' => $purchaseRequest->reservation->id,
            'delivery_method' => 'PICKUP',
        ], $this->withTenant($buyer));

        $dealResponse->assertCreated()
            ->assertJsonPath('data.status', Deal::STATUS_PENDING_SELLER_APPROVAL)
            ->assertJsonPath('data.purchase_request_id', $purchaseRequestId)
            ->assertJsonPath('data.quantity', '40.00');

        $dealId = $dealResponse->json('data.id');

        // The reservation is consumed by the deal, so it cannot fund a second one.
        $this->assertDatabaseHas('reservations', [
            'id' => $purchaseRequest->reservation->id,
            'status' => Reservation::STATUS_CONVERTED_TO_DEAL,
        ]);

        // 7. From here the state machine speaks: the seller confirms.
        Sanctum::actingAs($sellerUser);
        $this->postJson('/api/v1/deals/' . $dealId . '/transition', [
            'status' => Deal::STATUS_ACCEPTED,
        ], $this->withTenant($seller))->assertOk()
            ->assertJsonPath('data.status', Deal::STATUS_ACCEPTED);
    }

    public function test_the_platform_does_not_claim_a_payment_it_never_saw(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer');
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller');

        $offer = $this->offer($seller);
        $reservation = $this->reserveDirectly($offer, $buyerUser, $buyer);

        Sanctum::actingAs($buyerUser);
        $dealId = $this->postJson('/api/v1/deals', [
            'reservation_id' => $reservation->id,
            'delivery_method' => 'PICKUP',
        ], $this->withTenant($buyer))->assertCreated()->json('data.id');

        // `escrow` is off in this deployment: HASSAL is not holding anybody's
        // money, and marking a deal escrow-funded would say that it is.
        $this->postJson('/api/v1/deals/' . $dealId . '/transition', [
            'status' => Deal::STATUS_ESCROW_FUNDED,
        ], $this->withTenant($buyer))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'STATE_TRANSITION_FAILED');

        $this->assertDatabaseCount('escrows', 0);
    }
}
