<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * The offer lifecycle, enforced rather than described.
 *
 * §15-§18 define seven offer states and forbid the rest. That is only worth
 * anything if a listing cannot be bought while it is a draft, cannot publish
 * itself past the moderation queue, cannot be edited under a buyer's feet, and
 * cannot hold an eighth status the database has never heard of. Each test below
 * attacks one of those claims through the same endpoints a client uses.
 */
class OfferLifecycleTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\Organization}
     */
    protected function seller()
    {
        return $this->accountWithOrganization('SellerAdmin', 'seller', 'شركة تجزئة');
    }

    protected function createPayload(ProductVariant $variant, Warehouse $warehouse): array
    {
        return [
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'category_id' => $variant->product->category_id,
            'title_ar' => 'دقيق قمح عبوة ٥٠ كجم',
            'opportunity_reason' => Offer::REASON_SURPLUS_STOCK,
            'min_order_quantity' => 5,
            'total_quantity' => 100,
            'unit' => 'carton',
            'unit_price' => 18000,
            'currency_code' => 'YER',
        ];
    }

    public function test_a_draft_is_off_the_market_and_unreadable_by_another_business(): void
    {
        [$user, $seller] = $this->seller();
        $warehouse = $this->warehouse($seller);
        $variant = $this->productVariant($seller);

        Sanctum::actingAs($user);

        $offerId = $this->postJson('/api/v1/marketplace/offers', $this->createPayload($variant, $warehouse) + [
            'as_draft' => true,
        ], $this->withTenant($seller))
            ->assertCreated()
            ->assertJsonPath('data.status', Offer::STATUS_DRAFT)
            ->assertJsonPath('data.published_at', null)
            ->json('data.id');

        // Not in the public feed.
        $publicIds = array_column($this->getJson('/api/v1/marketplace/offers?per_page=50')->json('data'), 'id');
        $this->assertNotContains($offerId, $publicIds);

        // And not readable by another business. This route is public, so the
        // caller's identity is resolved deliberately: a member of the selling
        // organization may open their own draft, and nobody else may — with 404
        // either way, because "this exists but you cannot see it" is itself a
        // leak while ids are sequential.
        [$otherUser] = $this->accountWithOrganization('BuyerAdmin', 'buyer');
        Sanctum::actingAs($otherUser);

        $this->getJson('/api/v1/marketplace/offers/' . $offerId)
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/marketplace/offers/' . $offerId)
            ->assertOk()
            ->assertJsonPath('data.status', Offer::STATUS_DRAFT);
    }

    public function test_only_a_draft_or_a_paused_listing_can_be_edited(): void
    {
        [$user, $seller] = $this->seller();
        Sanctum::actingAs($user);

        $published = $this->offer($seller);

        // Rewriting the price of an offer buyers are reading is not an edit, it
        // is a different offer wearing the same URL.
        $this->putJson('/api/v1/marketplace/offers/' . $published->id, [
            'unit_price' => 999,
        ], $this->withTenant($seller))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OFFER_NOT_EDITABLE');

        $this->putJson('/api/v1/marketplace/offers/' . $published->id . '/status', [
            'status' => Offer::STATUS_PAUSED,
        ], $this->withTenant($seller))->assertOk();

        $this->putJson('/api/v1/marketplace/offers/' . $published->id, [
            'unit_price' => 17500.50,
            'title_ar' => 'دقيق قمح عبوة ٥٠ كجم — توضيح',
        ], $this->withTenant($seller))
            ->assertOk()
            ->assertJsonPath('data.unit_price', '17500.5000');

        // The line item follows the offer, or the offer would sell one thing and
        // invoice another.
        $this->assertSame('17500.5000', (string) DB::table('offer_items')->where('offer_id', $published->id)->value('unit_price'));
    }

    public function test_the_quantity_of_a_listing_already_sold_from_cannot_be_rewritten(): void
    {
        [$user, $seller] = $this->seller();
        $buyer = $this->organization('buyer');
        $buyerUser = $this->user('مشتري');

        $offer = $this->offer($seller);
        $this->reserveDirectly($offer, $buyerUser, $buyer, 30.0);

        Sanctum::actingAs($user);

        // Off the shelf first: an ACTIVE listing is not editable at all, so the
        // quantity guard below would never be reached.
        $this->putJson('/api/v1/marketplace/offers/' . $offer->id . '/status', [
            'status' => Offer::STATUS_PAUSED,
        ], $this->withTenant($seller))->assertOk();

        $this->putJson('/api/v1/marketplace/offers/' . $offer->id, [
            'min_order_quantity' => 1,
            'unit' => 'carton',
            'total_quantity' => 10,
        ], $this->withTenant($seller))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OFFER_QUANTITY_COMMITTED');
    }

    public function test_moderation_is_a_platform_permission_not_a_sellers(): void
    {
        $this->setSetting('offer.requires_review', '1', 'boolean');

        [$sellerUser, $seller] = $this->seller();
        Sanctum::actingAs($sellerUser);

        $offerId = $this->postJson('/api/v1/marketplace/offers', [
            'warehouse_id' => $this->warehouse($seller)->id,
            'product_variant_id' => ($variant = $this->productVariant($seller))->id,
            'category_id' => $variant->product->category_id,
            'title_ar' => 'سكر أبيض عبوة ٢٥ كجم',
            'opportunity_reason' => Offer::REASON_SURPLUS_STOCK,
            'min_order_quantity' => 5,
            'total_quantity' => 100,
            'unit' => 'carton',
            'unit_price' => 21000,
        ], $this->withTenant($seller))
            ->assertCreated()
            ->assertJsonPath('data.status', Offer::STATUS_PENDING_REVIEW)
            ->json('data.id');

        // The seller holds offers.publish. Publishing themselves out of the
        // review queue would make the queue decorative.
        $this->putJson('/api/v1/marketplace/offers/' . $offerId . '/status', [
            'status' => Offer::STATUS_ACTIVE,
        ], $this->withTenant($seller))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_OFFER_TRANSITION');

        $this->putJson('/api/v1/marketplace/offers/' . $offerId . '/review', [
            'decision' => 'APPROVE',
        ], $this->withTenant($seller))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'PERMISSION_DENIED')
            ->assertJsonPath('error.details.required_permission', 'offers.moderate');

        [$adminUser] = $this->accountWithOrganization('SuperAdmin', 'seller', 'منصة حصّال');
        Sanctum::actingAs($adminUser);

        $this->putJson('/api/v1/marketplace/offers/' . $offerId . '/review', [
            'decision' => 'APPROVE',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Offer::STATUS_ACTIVE);

        $this->assertNotNull(Offer::find($offerId)->published_at);
    }

    public function test_a_returned_offer_goes_back_to_the_seller_with_the_reason(): void
    {
        $this->setSetting('offer.requires_review', '1', 'boolean');

        [$sellerUser, $seller] = $this->seller();
        Sanctum::actingAs($sellerUser);

        $offer = $this->offer($seller, [
            'status' => Offer::STATUS_PENDING_REVIEW,
            'published_at' => null,
        ]);

        [$adminUser] = $this->accountWithOrganization('SuperAdmin', 'seller', 'منصة حصّال');
        Sanctum::actingAs($adminUser);

        $this->putJson('/api/v1/marketplace/offers/' . $offer->id . '/review', [
            'decision' => 'RETURN',
        ])->assertStatus(422)->assertJsonPath('error.details.reason.0', 'The reason field is required when decision is RETURN.');

        $this->putJson('/api/v1/marketplace/offers/' . $offer->id . '/review', [
            'decision' => 'RETURN',
            'reason' => 'سعر العبوة غير واضح وهل يشمل النقل',
        ])->assertOk()->assertJsonPath('data.status', Offer::STATUS_DRAFT);

        $this->assertSame(
            'سعر العبوة غير واضح وهل يشمل النقل',
            Offer::find($offer->id)->metadata['returned_reason']
        );

        // RETURN is only useful if the seller can act on it: edit, resubmit.
        Sanctum::actingAs($sellerUser);

        $this->putJson('/api/v1/marketplace/offers/' . $offer->id, [
            'description_ar' => 'السعر شامل التوصيل داخل عدن',
        ], $this->withTenant($seller))->assertOk();

        $this->postJson('/api/v1/marketplace/offers/' . $offer->id . '/submit', [], $this->withTenant($seller))
            ->assertOk()
            ->assertJsonPath('data.status', Offer::STATUS_PENDING_REVIEW);
    }

    public function test_only_a_submitted_offer_can_be_moderated(): void
    {
        [$adminUser] = $this->accountWithOrganization('SuperAdmin', 'seller', 'منصة حصّال');
        Sanctum::actingAs($adminUser);

        $live = $this->offer($this->organization('seller'), ['status' => Offer::STATUS_ACTIVE]);

        $this->putJson('/api/v1/marketplace/offers/' . $live->id . '/review', [
            'decision' => 'APPROVE',
        ])->assertStatus(422)->assertJsonPath('error_code', 'OFFER_NOT_AWAITING_REVIEW');
    }

    public function test_a_seller_controls_the_shelf_and_cancels_only_with_the_right_grant(): void
    {
        [, $seller] = $this->seller();
        $offer = $this->offer($seller);

        [$staffUser] = $this->accountWithOrganization('SellerStaff', 'seller', 'مخزن');

        Sanctum::actingAs($staffUser);
        // This account belongs to a different business, so it never reaches the
        // offer at all: the tenant middleware refuses to put another company in
        // context, which stops IDOR before a controller has to remember it.
        $this->putJson('/api/v1/marketplace/offers/' . $offer->id . '/status', [
            'status' => Offer::STATUS_PAUSED,
        ], $this->withTenant($seller))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ORGANIZATION_ACCESS_DENIED');

        // Give the staff account a membership in the same business: pausing is
        // within their role, cancelling is not.
        $this->membership($seller, $staffUser, 'staff');

        $this->putJson('/api/v1/marketplace/offers/' . $offer->id . '/status', [
            'status' => Offer::STATUS_PAUSED,
        ], $this->withTenant($seller))
            ->assertOk()
            ->assertJsonPath('data.status', Offer::STATUS_PAUSED);

        $this->putJson('/api/v1/marketplace/offers/' . $offer->id . '/status', [
            'status' => Offer::STATUS_ACTIVE,
        ], $this->withTenant($seller))
            ->assertOk()
            ->assertJsonPath('data.status', Offer::STATUS_ACTIVE);

        $this->putJson('/api/v1/marketplace/offers/' . $offer->id . '/status', [
            'status' => Offer::STATUS_CANCELLED,
            'reason' => 'لا نحتاج السحب',
        ], $this->withTenant($seller))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'PERMISSION_DENIED')
            ->assertJsonPath('error.details.required_permission', 'offers.cancel');

        $this->assertNotSame(Offer::STATUS_CANCELLED, Offer::find($offer->id)->status);
    }

    public function test_cancelling_is_terminal_for_the_seller(): void
    {
        [$user, $seller] = $this->seller();
        $offer = $this->offer($seller);

        Sanctum::actingAs($user);
        $this->putJson('/api/v1/marketplace/offers/' . $offer->id . '/status', [
            'status' => Offer::STATUS_CANCELLED,
        ], $this->withTenant($seller))->assertOk();

        $this->putJson('/api/v1/marketplace/offers/' . $offer->id . '/status', [
            'status' => Offer::STATUS_ACTIVE,
        ], $this->withTenant($seller))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_OFFER_TRANSITION');

        // An unpublished state cannot be resurrected by asking for a state the
        // vocabulary does not contain.
        $this->putJson('/api/v1/marketplace/offers/' . $offer->id . '/status', [
            'status' => 'ARCHIVED',
        ], $this->withTenant($seller))
            ->assertStatus(422)
            ->assertJsonPath('error.details.status.0', 'The selected status is invalid.');
    }

    public function test_running_out_of_stock_is_the_only_inventory_fact_a_status_may_tell(): void
    {
        [, $seller] = $this->seller();
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer');

        $offer = $this->offer($seller, ['total_quantity' => 50, 'available_quantity' => 50]);

        $reservation = $this->reserveDirectly($offer, $buyerUser, $buyer, 50.0);

        $offer->refresh();
        $this->assertSame(Offer::STATUS_SOLD_OUT, $offer->status);
        $this->assertSame('0.00', (string) $offer->available_quantity);
        $this->assertSame('50.00', (string) $offer->reserved_quantity);

        // The stock came back, so the shelf may say so again.
        app(\App\Services\InventoryReservationService::class)->release($reservation, 'test release');

        $offer->refresh();
        $this->assertSame(Offer::STATUS_ACTIVE, $offer->status);
        $this->assertSame('50.00', (string) $offer->available_quantity);
    }

    public function test_the_database_rejects_a_status_the_product_does_not_define(): void
    {
        [, $seller] = $this->seller();
        $offer = $this->offer($seller);

        try {
            DB::table('offers')->where('id', $offer->id)->update(['status' => 'FULLY_RESERVED']);
            $this->fail('The offers.status CHECK constraint did not fire.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('offers_status_is_canonical', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('offers', 'status'));
        $this->assertDatabaseHas('offers', ['id' => $offer->id, 'status' => Offer::STATUS_ACTIVE]);
    }

    public function test_an_offer_cannot_be_published_with_nothing_available(): void
    {
        [$user, $seller] = $this->seller();
        Sanctum::actingAs($user);

        $offer = $this->offer($seller, [
            'status' => Offer::STATUS_DRAFT,
            'total_quantity' => 20,
            'available_quantity' => 0,
            'reserved_quantity' => 20,
            'published_at' => null,
        ]);

        $this->postJson('/api/v1/marketplace/offers/' . $offer->id . '/submit', [], $this->withTenant($seller))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OFFER_HAS_NO_STOCK');
    }

    public function test_only_a_draft_can_be_discarded_and_a_published_one_is_only_withdrawn(): void
    {
        [$user, $seller] = $this->seller();
        Sanctum::actingAs($user);

        $live = $this->offer($seller);
        $this->deleteJson('/api/v1/marketplace/offers/' . $live->id, [], $this->withTenant($seller))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OFFER_NOT_DELETABLE');

        $draft = $this->offer($seller, ['status' => Offer::STATUS_DRAFT, 'published_at' => null]);
        $this->deleteJson('/api/v1/marketplace/offers/' . $draft->id, [], $this->withTenant($seller))
            ->assertOk();

        // Soft delete: the row remains for the audit trail, the model no longer serves it.
        $this->assertNull(Offer::find($draft->id));
        $this->assertSoftDeleted('offers', ['id' => $draft->id]);
    }

    public function test_the_platform_does_not_accept_a_market_price_it_cannot_source(): void
    {
        [$user, $seller] = $this->seller();
        Sanctum::actingAs($user);

        $warehouse = $this->warehouse($seller);
        $variant = $this->productVariant($seller);

        $response = $this->postJson('/api/v1/marketplace/offers', [
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'category_id' => $variant->product->category_id,
            'title_ar' => 'زيت دوار عبوة ١٦ لتر',
            'opportunity_reason' => Offer::REASON_SLOW_MOVING,
            'min_order_quantity' => 1,
            'total_quantity' => 40,
            'unit' => 'carton',
            'unit_price' => 30000,
            // An unsourced number offered as "the market": it must not be stored,
            // echoed back, or turned into a discount the platform invented.
            'market_reference_price' => 99999,
        ], $this->withTenant($seller));

        $response->assertCreated()
            ->assertJsonMissingPath('data.market_reference_price')
            ->assertJsonMissingPath('data.discount_percentage');

        $this->assertFalse(Schema::hasColumn('offers', 'market_reference_price'));
        $this->assertFalse(Schema::hasColumn('offers', 'discount_percentage'));
        $this->assertSame('30000.0000', (string) Offer::find($response->json('data.id'))->unit_price);
    }
}
