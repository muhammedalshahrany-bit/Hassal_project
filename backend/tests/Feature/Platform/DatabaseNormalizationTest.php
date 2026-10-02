<?php

namespace Tests\Feature\Platform;

use App\Marketplace\Models\Offer;
use App\Catalog\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * Facts the platform stores once, in one place, with something below the code
 * making sure of it.
 *
 * The 2026-09-25 normalization pass removed two kinds of redundancy and added one
 * kind of guard, and each of those is a claim a later edit could quietly undo:
 *
 *   - a copy of another row's answer (`offers.category_id`, kept beside the
 *     product it was copied from),
 *   - a copy of another column's answer on the same row
 *     (`deals.transaction_currency` beside `currency_code`,
 *     `deals.exchange_rate` beside `exchange_rate_snapshot`),
 *   - a value whose only rule was written in the controller that happened to
 *     write it (`currency_code`, in eleven tables, none of them pointing at
 *     `currencies`).
 *
 * The first two are anomalies with no interesting failure mode: nothing breaks
 * when the copies are written, and everything is ambiguous when they are not. So
 * these tests do not check that a writer behaves — they check that the *shape*
 * has no room for a second answer, and that the one answer now moves with the
 * thing it describes.
 */
class DatabaseNormalizationTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /**
     * A listing belongs to its product's category because it belongs to the
     * product — not because a number was written into its own row at creation
     * and left there.
     *
     * This is the whole argument for dropping `offers.category_id` in one test:
     * reclassify the goods and the shelf the buyer browses follows immediately,
     * without anyone remembering to update the listings of it.
     */
    public function test_a_listing_category_follows_the_product_that_owns_it(): void
    {
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد جملة');
        $warehouse = $this->warehouse($seller);
        $category = $this->category();
        $variant = $this->productVariant($seller, $category);

        Sanctum::actingAs($sellerUser);

        $offerId = $this->postJson('/api/v1/marketplace/offers', [
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'title_ar' => 'سكر عبوة ٥٠ كجم',
            'opportunity_reason' => Offer::REASON_SURPLUS_STOCK,
            'min_order_quantity' => 5,
            'total_quantity' => 100,
            'unit' => 'carton',
            'unit_price' => 40000,
            'currency_code' => 'YER',
        ], $this->withTenant($seller))
            ->assertCreated()
            ->assertJsonPath('data.category_id', $category->id)
            ->json('data.id');

        // The client still reads one key, in one shape, from the same endpoint.
        $this->getJson('/api/v1/marketplace/offers?category_id=' . $category->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $offerId);

        // …and the offer's own row has nothing left to disagree with the product.
        $this->assertFalse(
            Schema::hasColumn('offers', 'category_id'),
            'the listing reaches its category through the variant it names'
        );

        $moved = $this->category();
        Product::query()->where('id', $variant->product_id)->update(['category_id' => $moved->id]);

        $this->assertSame(
            $moved->id,
            Offer::find($offerId)->category_id,
            'A reclassified product left its listings behind in the category it had left.'
        );

        $this->getJson('/api/v1/marketplace/offers?category_id=' . $moved->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $offerId);

        $this->getJson('/api/v1/marketplace/offers?category_id=' . $category->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/marketplace/offers/' . $offerId)
            ->assertOk()
            ->assertJsonPath('data.category_id', $moved->id);
    }

    /**
     * The mismatch refusal survives the column it used to protect.
     *
     * `category_id` in the request is no longer stored anywhere, so it is tempting
     * to stop looking at it. A seller whose client believes the goods are in
     * another category is stating a wrong fact about the listing, and the answer
     * to that has not become "we silently stored something else".
     */
    public function test_goods_cannot_be_listed_under_a_category_the_product_is_not_in(): void
    {
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد جملة');
        $variant = $this->productVariant($seller);

        Sanctum::actingAs($sellerUser);

        $this->postJson('/api/v1/marketplace/offers', [
            'warehouse_id' => $this->warehouse($seller)->id,
            'product_variant_id' => $variant->id,
            'category_id' => $this->category()->id,
            'title_ar' => 'سكر عبوة ٥٠ كجم',
            'opportunity_reason' => Offer::REASON_SURPLUS_STOCK,
            'min_order_quantity' => 5,
            'total_quantity' => 100,
            'unit' => 'carton',
            'unit_price' => 40000,
            'currency_code' => 'YER',
        ], $this->withTenant($seller))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CATEGORY_PRODUCT_MISMATCH');
    }

    /**
     * Every amount on the platform is denominated in a declared currency, and the
     * database — not eleven controllers — is the one that says so.
     */
    public function test_a_currency_the_platform_never_declared_cannot_hold_money(): void
    {
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد جملة');
        $offer = $this->offer($seller);

        $this->assertDatabaseHas('currencies', ['iso_code' => 'YER']);

        // A code with no `currencies` row has no symbol, no decimal count and no
        // rate, so an amount written in it can be summed and exported but never
        // read. The row below is what the old schema could not refuse.
        $this->expectException(QueryException::class);

        DB::table('offers')->where('id', $offer->id)->update(['currency_code' => 'ZZZ']);
    }

    /**
     * The set of currency-bearing tables and the set of guarded ones are the same set.
     *
     * Counted from the live schema rather than from the migration's list, so a
     * future table that carries money without carrying the key is what fails here —
     * which is precisely the shape the eleven existing tables had before.
     */
    public function test_every_table_holding_money_names_the_currency_vocabulary(): void
    {
        $bearing = array_map(
            fn ($row) => $row->TABLE_NAME,
            DB::select(
                "SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'currency_code'
                    AND TABLE_NAME <> 'currencies'"
            )
        );

        $guarded = array_map(
            fn ($row) => $row->TABLE_NAME,
            DB::select(
                "SELECT DISTINCT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'currency_code'
                    AND REFERENCED_TABLE_NAME = 'currencies'"
            )
        );

        sort($bearing);
        sort($guarded);

        $this->assertNotEmpty($bearing, 'the schema lost its currency columns entirely');
        $this->assertSame(
            $bearing,
            $guarded,
            'Tables carry a currency_code that no foreign key ties to `currencies`: '
                . implode(', ', array_diff($bearing, $guarded))
        );
    }

    /**
     * A deal states its currency and its rate once.
     *
     * The retired names were not exposed in the contract and were read by nothing,
     * so the only thing that can go wrong now is someone adding them back — a
     * second place for the same number to live, and a future reader asking which
     * one the platform trusts.
     */
    public function test_a_deal_names_its_currency_and_its_rate_once(): void
    {
        $this->assertTrue(Schema::hasColumn('deals', 'currency_code'));
        $this->assertTrue(Schema::hasColumn('deals', 'exchange_rate_snapshot'));

        $this->assertFalse(
            Schema::hasColumn('deals', 'transaction_currency'),
            'a second column for the deal\'s currency is a second answer to one question'
        );
        $this->assertFalse(
            Schema::hasColumn('deals', 'exchange_rate'),
            'a rate beside the rate snapshot is the same number twice'
        );

        $pricing = app(\App\Trading\Services\PricingAndCurrencyService::class)
            ->calculateDealPricing(2.0, 25000.0, 'YER');

        $this->assertArrayNotHasKey('transaction_currency', $pricing);
        $this->assertArrayNotHasKey('exchange_rate', $pricing);
        $this->assertSame('YER', $pricing['currency_code']);
        $this->assertSame(1.000000, $pricing['exchange_rate_snapshot']);
    }
}
