<?php

namespace Tests\Feature\Platform;

use App\Trading\Models\Deal;
use App\Trading\Models\DealHandover;
use App\Marketplace\Models\Offer;
use App\Trading\Models\PurchaseRequest;
use App\Trading\Models\Reservation;
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

        // 8. Acceptance issues the order's delivery code, and the goods go out
        //    against it. §1 of the brief makes this scan part of the cycle rather
        //    than a convenience: an order cannot be closed as received unless its
        //    own label has been read.
        $code = DealHandover::where('deal_id', $dealId)->value('code');
        $this->assertNotNull($code, 'The seller\'s acceptance is what issues the handover code.');

        foreach ([Deal::STATUS_PROCESSING, Deal::STATUS_DISPATCHED, Deal::STATUS_DELIVERED] as $status) {
            Sanctum::actingAs($sellerUser);
            $this->postJson('/api/v1/deals/' . $dealId . '/transition', [
                'status' => $status,
            ], $this->withTenant($seller))->assertOk();
        }

        // 9. The buyer's man at the gate scans the label.
        Sanctum::actingAs($buyerUser);
        $this->postJson('/api/v1/deals/' . $dealId . '/handover/verify', [
            'code' => $code,
            'notes' => 'سلّمت العبوات عند البوابة',
        ], $this->withTenant($buyer))->assertCreated()
            ->assertJsonPath('data.status', DealHandover::STATUS_USED);

        // 10. And the buyer closes the order on their own receipt.
        $this->postJson('/api/v1/deals/' . $dealId . '/transition', [
            'status' => Deal::STATUS_COMPLETED,
        ], $this->withTenant($buyer))->assertOk()
            ->assertJsonPath('data.status', Deal::STATUS_COMPLETED);

        // The quantity the acceptance held is now a sale, and nothing about the
        // listing's total moved twice.
        $offer->refresh();
        $this->assertSame(60.0, (float) $offer->available_quantity);
        $this->assertSame(0.0, (float) $offer->reserved_quantity);
        $this->assertSame(40.0, (float) $offer->sold_quantity);

        $this->assertDatabaseCount('deal_status_histories', 5);
    }

    /**
     * The same road walked backwards, which is the half a demo of only successes
     * never shows.
     *
     * A buyer who backs out after the seller has accepted must get their quantity
     * onto the shelf again, exactly once, and the delivery code the acceptance
     * issued must stop working with the order it belonged to. §5 names both of
     * these as things to verify, and the version of this product that passes the
     * first and fails the second is the one where a cancelled pallet is scanned
     * onto someone else's receipt.
     */
    public function test_a_call_off_returns_the_held_quantity_and_retires_the_code(): void
    {
        [$sellerUser, $seller] = $this->accountWithOrganization(null, 'seller', 'مورد جملة');
        [$buyerUser, $buyer] = $this->accountWithOrganization(null, 'buyer', 'بقال');

        $offer = $this->offer($seller);
        $reservation = $this->reserveDirectly($offer, $buyerUser, $buyer, 25.0);

        Sanctum::actingAs($buyerUser);
        $dealId = $this->postJson('/api/v1/deals', [
            'reservation_id' => $reservation->id,
            'delivery_method' => 'PICKUP',
        ], $this->withTenant($buyer))->assertCreated()->json('data.id');

        Sanctum::actingAs($sellerUser);
        $this->postJson('/api/v1/deals/' . $dealId . '/transition', [
            'status' => Deal::STATUS_ACCEPTED,
        ], $this->withTenant($seller))->assertOk();

        $offer->refresh();
        $this->assertSame(75.0, (float) $offer->available_quantity);
        $this->assertSame(25.0, (float) $offer->reserved_quantity);
        $code = DealHandover::where('deal_id', $dealId)->value('code');
        $this->assertNotNull($code);

        Sanctum::actingAs($buyerUser);
        $this->postJson('/api/v1/deals/' . $dealId . '/transition', [
            'status' => Deal::STATUS_CANCELLED,
            'notes' => 'أُلغيت الصفقة لانتهاء الموسم',
        ], $this->withTenant($buyer))->assertOk()
            ->assertJsonPath('data.status', Deal::STATUS_CANCELLED);

        $offer->refresh();
        $this->assertSame(100.0, (float) $offer->available_quantity);
        $this->assertSame(0.0, (float) $offer->reserved_quantity);
        $this->assertSame(0.0, (float) $offer->sold_quantity);

        // The code went with the order, and the order is not going anywhere else.
        $this->postJson('/api/v1/deals/' . $dealId . '/handover/verify', [
            'code' => $code,
        ], $this->withTenant($buyer))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'HANDOVER_INVALIDATED');

        $this->postJson('/api/v1/deals/' . $dealId . '/transition', [
            'status' => Deal::STATUS_COMPLETED,
        ], $this->withTenant($buyer))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'STATE_TRANSITION_FAILED');
    }
}
