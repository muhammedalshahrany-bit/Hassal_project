<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * Who may read and write the catalog.
 *
 * `products.organization_id` is nullable, and the two states mean different
 * things: an unowned row is a platform reference entry that any business may
 * list against, an owned row is that business' own SKU book. The endpoint
 * ignored the distinction and answered with every row in the table, so a
 * business opening its catalog was reading its competitors' product names,
 * internal codes and barcodes — and could publish an offer under another
 * business' variant, because the offer route checked the warehouse's owner but
 * not the goods'.
 */
class CatalogScopeTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Organization} */
    protected function seller(): array
    {
        return $this->accountWithOrganization('SellerAdmin', 'seller', 'منشأة البائع');
    }

    protected function productRow(int|string|null $organizationId, string $name): Product
    {
        return Product::create([
            'organization_id' => $organizationId,
            'category_id' => $this->category()->id,
            'name_ar' => $name,
            'unit' => 'carton',
            'is_active' => true,
        ]);
    }

    public function test_a_business_reads_its_own_catalog_and_the_platform_entries_only(): void
    {
        [$mine, $mineOrg] = $this->seller();
        [$theirs, $theirsOrg] = $this->seller();

        $own = $this->productRow($mineOrg->id, 'منتج خاص بي');
        $foreign = $this->productRow($theirsOrg->id, 'منتج خاص بالمنشأة الأخرى');
        $shared = $this->productRow(null, 'مرجع عام من المنصة');

        Sanctum::actingAs($mine);
        $ids = array_column(
            $this->getJson('/api/v1/catalog/products?per_page=100', $this->withTenant($mineOrg))
                ->assertOk()->json('data'),
            'id'
        );

        $this->assertContains($own->id, $ids);
        $this->assertContains($shared->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_another_business_variants_are_refused_rather_than_listed(): void
    {
        [$mine, $mineOrg] = $this->seller();
        [$theirs, $theirsOrg] = $this->seller();

        $foreign = $this->productVariant($theirsOrg);

        Sanctum::actingAs($mine);
        $this->getJson('/api/v1/catalog/products/' . $foreign->product_id . '/variants', $this->withTenant($mineOrg))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'VARIANT_SOURCE_NOT_ACCESSIBLE');

        // The owner still reads it, so the rule is scoping and not a blanket
        // break of the picker.
        Sanctum::actingAs($theirs);
        $this->getJson('/api/v1/catalog/products/' . $foreign->product_id . '/variants', $this->withTenant($theirsOrg))
            ->assertOk()
            ->assertJsonPath('data.0.id', $foreign->id);
    }

    public function test_an_offer_cannot_be_published_under_another_business_variant(): void
    {
        [$mine, $mineOrg] = $this->seller();
        $warehouse = $this->warehouse($mineOrg);
        [, $theirsOrg] = $this->seller();
        $foreignVariant = $this->productVariant($theirsOrg);

        Sanctum::actingAs($mine);
        $this->postJson('/api/v1/marketplace/offers', [
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $foreignVariant->id,
            'title_ar' => 'عرض منسوب لصنف غير مملوك',
            'opportunity_reason' => Offer::REASON_SURPLUS_STOCK,
            'min_order_quantity' => 1,
            'total_quantity' => 10,
            'unit' => 'carton',
            'unit_price' => 1000,
            'currency_code' => 'YER',
        ], $this->withTenant($mineOrg))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'VARIANT_NOT_OWNED');

        $this->assertSame(0, Offer::query()->count());
    }

    public function test_a_shared_platform_variant_stays_listable_by_any_business(): void
    {
        [$mine, $mineOrg] = $this->seller();
        $warehouse = $this->warehouse($mineOrg);
        $sharedProduct = $this->productRow(null, 'صنف مرجعي للمنصة');

        $variant = ProductVariant::create([
            'product_id' => $sharedProduct->id,
            'sku' => 'SHARED-' . strtoupper(substr(md5((string) $sharedProduct->id), 0, 8)),
            'name_ar' => 'عبوة قياسية',
            'name_en' => 'Standard pack',
            'is_active' => true,
        ]);

        Sanctum::actingAs($mine);
        $this->postJson('/api/v1/marketplace/offers', [
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'title_ar' => 'عرض على صنف مرجعي',
            'opportunity_reason' => Offer::REASON_SURPLUS_STOCK,
            'min_order_quantity' => 1,
            'total_quantity' => 10,
            'unit' => 'carton',
            'unit_price' => 1000,
            'currency_code' => 'YER',
        ], $this->withTenant($mineOrg))->assertCreated();
    }

    public function test_a_repeated_product_code_is_a_validation_error_not_a_crash(): void
    {
        [$user, $org] = $this->seller();
        $category = $this->category();

        Sanctum::actingAs($user);

        $payload = [
            'category_id' => $category->id,
            'name_ar' => 'أرز ' . strtoupper(substr(md5('x'), 0, 6)),
            'unit' => 'kg',
            'sku' => 'DUP-CODE',
        ];

        $this->postJson('/api/v1/catalog/products', $payload, $this->withTenant($org))->assertCreated();

        // product_variants.sku is UNIQUE and the default variant inherits the
        // product code; before the check existed this second write answered 500.
        $this->postJson('/api/v1/catalog/products', $payload, $this->withTenant($org))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame(1, ProductVariant::where('sku', 'DUP-CODE')->count());
    }

    public function test_a_catalog_write_needs_an_organization_to_belong_to(): void
    {
        // `catalog.manage` is a standing inside a company now, so an account that
        // belongs to none is refused outright: the row it would write has no
        // owner, and a product with no owner is shared vocabulary that every
        // other business then lists against.
        $stranger = $this->user('بائع بلا منشأة');
        Sanctum::actingAs($stranger);

        $this->postJson('/api/v1/catalog/products', [
            'category_id' => $this->category()->id,
            'name_ar' => 'صنف بلا مالك',
            'unit' => 'carton',
        ])->assertStatus(403)
            ->assertJsonPath('error_code', 'PERMISSION_DENIED')
            ->assertJsonPath('error.details.required_permission', 'catalog.manage');

        $this->assertSame(0, Product::query()->count());

        // The platform is the one account allowed to write an unowned row, and it
        // does so on purpose: a reference entry, not a business' SKU that lost
        // its owner. The two look identical in the table and are not the same
        // thing in the product, so the distinction is asserted where it is made.
        $platform = $this->user('منصة حصّال');
        $platform->assignRole('SuperAdmin');
        Sanctum::actingAs($platform);

        $reference = $this->postJson('/api/v1/catalog/products', [
            'category_id' => $this->category()->id,
            'name_ar' => 'مرجع مشترك',
            'unit' => 'carton',
        ])->assertCreated();

        $this->assertNull($reference->json('data.organization_id'));
        $this->assertSame(1, Product::whereNull('organization_id')->count());
    }
}
