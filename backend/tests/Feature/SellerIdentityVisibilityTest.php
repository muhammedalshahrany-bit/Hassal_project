<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * What a listing says about the business that published it.
 *
 * `offers.show_seller_identity` is a display choice, and the whole point of the
 * test surface here is that it is a *narrow* one: it removes the seller's name
 * from what a stranger reads, and removes nothing else. The offer keeps its real
 * `seller_organization_id`; a purchase request still names the company it is
 * addressed to; a platform reviewer still reads the real name, because a
 * reviewer who cannot see the counterparty cannot review anything.
 *
 * Three separate promises are proven, because each fails differently:
 *   1. Masking happens on the public read paths and not only on the card a
 *      screen happens to render — the list, the detail page, and the seller's
 *      own answer inside the comment thread.
 *   2. The seller's own organization reads its own listing unmasked, so its
 *      shelf and edit form cannot show it a blank where it typed a name.
 *   3. Hiding is opt-in and per-listing; the recorded default stays attributed,
 *      so switching this feature on changes nothing about what was already
 *      published.
 */
class SellerIdentityVisibilityTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
        $this->setFlag('community', true);
    }

    /** A seller and one listing, with the identity switch set as asked. */
    private function listing(bool $showIdentity, string $status = Offer::STATUS_ACTIVE): array
    {
        [$user, $org] = $this->accountWithOrganization('SellerAdmin', 'seller', 'شركة لا ترغب بالظهور');
        $warehouse = $this->warehouse($org);
        $variant = $this->productVariant($org);

        $offer = $this->offer($org, [
            'show_seller_identity' => $showIdentity,
            'status'               => $status,
            'title_ar'             => 'أرز بحرية ٥٠ كجم',
        ], $warehouse, $variant);

        return [$user, $org, $offer->fresh()];
    }

    public function test_publishing_can_record_a_request_to_hide_the_sellers_name(): void
    {
        [$user, $org] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مصنع يريد التحفظ');
        $warehouse = $this->warehouse($org);
        $variant = $this->productVariant($org);

        Sanctum::actingAs($user);
        $headers = $this->withTenant($org);

        $id = $this->withHeaders($headers)
            ->postJson('/api/v1/marketplace/offers', [
                'warehouse_id'         => $warehouse->id,
                'product_variant_id'   => $variant->id,
                'title_ar'             => 'دقيق فاخر',
                'opportunity_reason'   => Offer::REASON_SURPLUS_STOCK,
                'min_order_quantity'   => 1,
                'total_quantity'       => 20,
                'unit'                 => 'carton',
                'unit_price'           => 30000,
                'currency_code'        => 'YER',
                'show_seller_identity' => false,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertFalse((bool) Offer::find($id)->show_seller_identity);

        // And the default stays the other way: a publish that says nothing is a
        // named listing, not an anonymous one.
        $named = $this->withHeaders($headers)
            ->postJson('/api/v1/marketplace/offers', [
                'warehouse_id'         => $warehouse->id,
                'product_variant_id'   => $variant->id,
                'title_ar'             => 'سكر',
                'opportunity_reason'   => Offer::REASON_SURPLUS_STOCK,
                'min_order_quantity'   => 1,
                'total_quantity'       => 20,
                'unit'                 => 'carton',
                'unit_price'           => 30000,
                'currency_code'        => 'YER',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertTrue((bool) Offer::find($named)->show_seller_identity);
    }

    public function test_a_stranger_reading_the_public_list_gets_no_name_no_registration_and_no_vat(): void
    {
        [, $org, $hidden] = $this->listing(false);
        [, , $shown] = $this->listing(true);

        $rows = $this->getJson('/api/v1/marketplace/offers')
            ->assertOk()
            ->json('data');

        $byId = collect($rows)->keyBy('id');

        $masked = $byId[$hidden->id];
        $this->assertNull($masked['seller_name_ar']);
        $this->assertNull($masked['seller_name_en']);
        $this->assertNull($masked['seller_cr']);
        $this->assertNull($masked['seller_vat']);
        $this->assertFalse($masked['show_seller_identity']);

        // The card still needs something to compare listings on: whether the
        // platform has reviewed this business is a statement about its conduct
        // here, not about who it is.
        $this->assertSame(Organization::STATUS_VERIFIED, $masked['seller_status']);

        // Discovery is the point of the listing. Hiding a name must not hide
        // where the goods physically are, or the fast path stops working.
        $this->assertSame($org->city, $masked['warehouse_city']);
        $this->assertNull($masked['warehouse_name'], 'The depot is usually named after its owner.');
        $this->assertNull($masked['warehouse_address']);

        $visible = $byId[$shown->id];
        $this->assertSame($shown->seller->name_ar, $visible['seller_name_ar']);
        $this->assertSame($shown->seller->commercial_registration_number, $visible['seller_cr']);
        $this->assertTrue($visible['show_seller_identity']);
    }

    public function test_the_detail_page_answers_the_same_way_as_the_list(): void
    {
        [, , $hidden] = $this->listing(false);

        // If only the list masked, `GET /offers/{id}` would be a one-call
        // workaround for anyone who read a listing id off the public page.
        $detail = $this->getJson("/api/v1/marketplace/offers/{$hidden->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame($hidden->id, $detail['id']);
        $this->assertNull($detail['seller_name_ar']);
        $this->assertNull($detail['seller_cr']);
        $this->assertFalse($detail['show_seller_identity']);
    }

    public function test_the_selling_organization_still_reads_its_own_listing_in_full(): void
    {
        [$seller, $org, $hidden] = $this->listing(false);

        Sanctum::actingAs($seller);
        $headers = $this->withTenant($org);

        $mine = $this->withHeaders($headers)->getJson('/api/v1/marketplace/my-offers')
            ->assertOk()
            ->json('data');

        $row = collect($mine)->firstWhere('id', $hidden->id);
        $this->assertNotNull($row);
        $this->assertSame($org->name_ar, $row['seller_name_ar']);
        $this->assertSame($org->commercial_registration_number, $row['seller_cr']);

        // The same person reading the public detail route must also see the
        // truth: a form that edits `false` while showing a blank name would ask
        // the seller to guess what they are about to publish.
        $this->withHeaders($headers)
            ->getJson("/api/v1/marketplace/offers/{$hidden->id}")
            ->assertOk()
            ->assertJsonPath('data.seller_name_ar', $org->name_ar)
            ->assertJsonPath('data.show_seller_identity', false);
    }

    public function test_a_platform_reviewer_reads_the_name_the_market_does_not(): void
    {
        // A reviewer only ever sees a listing that reached them, so the fixture
        // is a submission rather than a live one.
        [, , $hidden] = $this->listing(false, Offer::STATUS_PENDING_REVIEW);

        $reviewer = $this->user('مراجع المنصة');
        $reviewer->assignRole('SuperAdmin');

        Sanctum::actingAs($reviewer);

        $row = collect($this->getJson('/api/v1/marketplace/moderation-queue?per_page=50')
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $hidden->id);

        $this->assertNotNull($row, 'The listing should reach the reviewer queue.');
        $this->assertSame($hidden->seller->name_ar, $row['seller_name_ar']);
        $this->assertArrayHasKey('moderation', $row);
    }

    public function test_the_sellers_own_answer_in_the_thread_carries_no_name_either(): void
    {
        [$seller, $org, $hidden] = $this->listing(false);

        [$buyerUser, $buyerOrg] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل يسأل');

        Sanctum::actingAs($buyerUser);
        $this->withHeaders($this->withTenant($buyerOrg))
            ->postJson("/api/v1/marketplace/offers/{$hidden->id}/comments", ['body' => 'هل المتوفر اليوم؟'])
            ->assertCreated();

        Sanctum::actingAs($seller);
        $this->withHeaders($this->withTenant($org))
            ->postJson("/api/v1/marketplace/offers/{$hidden->id}/comments", ['body' => 'نعم، الكمية متاحة.'])
            ->assertCreated();

        $comments = $this->getJson("/api/v1/marketplace/offers/{$hidden->id}/comments")
            ->assertOk()
            ->json('data.comments');

        $sellerReply = collect($comments)->firstWhere('is_seller_reply', true);
        $buyerQuestion = collect($comments)->firstWhere('is_seller_reply', false);

        $this->assertNotNull($sellerReply, 'The seller\'s own answer should have been attributed to them.');
        $this->assertTrue($sellerReply['identity_hidden']);
        $this->assertArrayNotHasKey('organization_name', $sellerReply);
        $this->assertArrayNotHasKey('author_name', $sellerReply);
        $this->assertArrayNotHasKey('organization_id', $sellerReply);

        // The buyer named themselves by asking in public. The seller's switch is
        // the seller's to make, and silently anonymising someone else's comment
        // would be the platform editing a record it has no basis to change.
        $this->assertSame($buyerOrg->name_ar, $buyerQuestion['organization_name']);
    }

    public function test_the_switch_travels_with_an_edit_and_not_with_the_lifecycle(): void
    {
        [$seller, $org, $offer] = $this->listing(true);

        // Pausing is how a seller reaches the editable states, and it must not
        // decide the identity question for them.
        Sanctum::actingAs($seller);
        $headers = $this->withTenant($org);

        $this->withHeaders($headers)
            ->putJson("/api/v1/marketplace/offers/{$offer->id}/status", ['status' => Offer::STATUS_PAUSED])
            ->assertOk();

        $this->withHeaders($headers)
            ->putJson("/api/v1/marketplace/offers/{$offer->id}", [
                'title_ar'           => 'أرز بحرية ٥٠ كجم',
                'opportunity_reason' => Offer::REASON_SURPLUS_STOCK,
                'min_order_quantity' => 1,
                'total_quantity'     => 20,
                'unit'               => 'carton',
                'unit_price'         => 24000,
                'show_seller_identity' => false,
            ])
            ->assertOk();

        $this->assertFalse((bool) $offer->fresh()->show_seller_identity);
        $this->assertSame(Offer::STATUS_PAUSED, $offer->fresh()->status);
    }
}
