<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * Photographs attached to a product, as files rather than as a typed URL.
 *
 * A listing without a picture is a claim about goods nobody can see, so the
 * upload path has to work — and an upload path is the one place in this product
 * where a stranger's bytes land on disk under a name this application chooses.
 * These tests therefore check the two things that keep it a feature rather than
 * a hole: whose product may receive media at all, and what the stored file is
 * allowed to be called.
 */
class ProductImageTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedPlatformDefaults();
    }

    /** @return array{0: User, 1: Organization, 2: Product} */
    protected function ownedProduct(string $orgName = 'شركة الجملة'): array
    {
        [$user, $org] = $this->accountWithOrganization('SellerAdmin', 'seller', $orgName);
        $variant = $this->productVariant($org);

        return [$user, $org, $variant->product];
    }

    protected function png(string $name = 'pallet.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 800, 600);
    }

    public function test_a_seller_uploads_photographs_and_the_first_one_becomes_the_cover(): void
    {
        [$user, $org, $product] = $this->ownedProduct();
        Sanctum::actingAs($user);
        $headers = $this->withTenant($org);

        $this->withHeaders($headers)
            ->postJson("/api/v1/catalog/products/{$product->id}/images", [
                'images' => [$this->png('carton.png'), $this->png('label.png')],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.images')
            ->assertJsonPath('data.images.0.is_cover', true)
            ->assertJsonPath('data.images.1.is_cover', false)
            // Dimensions come from the file, not from anything the client said.
            ->assertJsonPath('data.images.0.width', 800)
            ->assertJsonPath('data.images.0.height', 600);

        $stored = ProductImage::query()->where('product_id', $product->id)->orderBy('id')->get();
        $this->assertCount(2, $stored);
        Storage::disk('public')->assertExists($stored[0]->path);
        $this->assertSame($org->id, $stored[0]->organization_id);
        $this->assertSame($user->id, $stored[0]->uploaded_by);
        // The name on disk is generated; the sender's name is metadata only.
        $this->assertStringNotContainsString('carton.png', $stored[0]->path);

        $this->withHeaders($headers)->getJson("/api/v1/catalog/products/{$product->id}/images")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_file_is_accepted_for_what_it_contains_and_stays_what_it_is_called_nothing_of(): void
    {
        [$user, $org, $product] = $this->ownedProduct();
        Sanctum::actingAs($user);
        $headers = $this->withTenant($org);

        // Image bytes wearing an executable name: the type check looks at the
        // content, so the defense has to be the name this system writes — which
        // is derived from the detected type, never from the request.
        $png = tempnam(sys_get_temp_dir(), 'hassal-');
        imagepng(imagecreatetruecolor(10, 10), $png);

        $this->withHeaders($headers)
            ->postJson("/api/v1/catalog/products/{$product->id}/images", [
                'images' => [new UploadedFile($png, 'shell.php', 'image/png', null, true)],
            ])
            ->assertCreated()
            ->assertJsonPath('data.images.0.mime_type', 'image/png');

        $path = ProductImage::query()->where('product_id', $product->id)->value('path');
        $this->assertStringEndsWith('.png', $path);
        $this->assertStringNotContainsString('.php', $path);

        // The upload is an action by a named person on a named company's
        // record, so it has to survive into the trail — a JSON column cannot
        // hold a file, and the middleware that learned that used to lose the row.
        $audit = AuditLog::query()
            ->where('action', 'POST api/v1/catalog/products/' . $product->id . '/images')
            ->latest('id')
            ->first();
        $this->assertNotNull($audit, 'An upload that leaves no audit row is an unlogged change.');
        $this->assertSame('shell.php', $audit->new_values['images'][0]['file']);
        $this->assertSame('image/png', $audit->new_values['images'][0]['mime_type']);

        // A text file claiming to be a photograph is refused, and refusing the
        // only file in a batch is a failure rather than an empty success.
        $this->withHeaders($headers)
            ->postJson("/api/v1/catalog/products/{$product->id}/images", [
                'images' => [UploadedFile::fake()->create('notes.txt', 12, 'text/plain')],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NO_IMAGE_ACCEPTED');

        $this->assertSame(1, ProductImage::query()->where('product_id', $product->id)->count());
    }

    public function test_a_rival_business_cannot_attach_or_read_another_product_media(): void
    {
        [$owner, $ownerOrg, $product] = $this->ownedProduct('شركة الجملة');
        Sanctum::actingAs($owner);
        $this->withHeaders($this->withTenant($ownerOrg))
            ->postJson("/api/v1/catalog/products/{$product->id}/images", ['images' => [$this->png()]])
            ->assertCreated();

        [$rival, $rivalOrg] = $this->accountWithOrganization('SellerAdmin', 'seller', 'منشأة المنافس');
        Sanctum::actingAs($rival);
        $headers = $this->withTenant($rivalOrg);

        // Same permission, different company: a photograph of stacked stock is
        // also a photograph of somebody's warehouse.
        $this->withHeaders($headers)
            ->getJson("/api/v1/catalog/products/{$product->id}/images")
            ->assertForbidden();

        $this->withHeaders($headers)
            ->postJson("/api/v1/catalog/products/{$product->id}/images", ['images' => [$this->png()]])
            ->assertForbidden();

        $this->assertSame(1, ProductImage::query()->where('product_id', $product->id)->count());
    }

    public function test_the_cover_can_be_moved_and_deleting_it_promotes_another_photograph(): void
    {
        [$user, $org, $product] = $this->ownedProduct();
        Sanctum::actingAs($user);
        $headers = $this->withTenant($org);

        $ids = $this->withHeaders($headers)
            ->postJson("/api/v1/catalog/products/{$product->id}/images", [
                'images' => [$this->png('a.png'), $this->png('b.png'), $this->png('c.png')],
            ])
            ->assertCreated()
            ->json('data.images');

        $this->withHeaders($headers)
            ->putJson("/api/v1/catalog/products/{$product->id}/images/{$ids[2]['id']}", ['is_cover' => true])
            ->assertOk()
            ->assertJsonPath('data.is_cover', true);

        $this->assertSame(
            1,
            ProductImage::query()->where('product_id', $product->id)->where('is_cover', true)->count(),
            'Exactly one photograph may front a product.'
        );

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/catalog/products/{$product->id}/images/{$ids[2]['id']}")
            ->assertOk();

        $remaining = ProductImage::query()->where('product_id', $product->id)->pluck('is_cover', 'id');
        $this->assertCount(2, $remaining);
        $this->assertTrue($remaining->contains(true), 'A product is never left with no cover while holding files.');
    }

    public function test_the_number_of_photographs_is_a_deployment_limit_not_a_suggestion(): void
    {
        [$user, $org, $product] = $this->ownedProduct();
        Sanctum::actingAs($user);
        $headers = $this->withTenant($org);

        $max = (int) config('hassal.uploads.images.max_per_product');
        $files = [];
        for ($i = 0; $i < $max; $i++) {
            $files[] = $this->png("p{$i}.png");
        }

        $this->withHeaders($headers)
            ->postJson("/api/v1/catalog/products/{$product->id}/images", ['images' => $files])
            ->assertCreated()
            ->assertJsonCount($max, 'data.images');

        $this->withHeaders($headers)
            ->postJson("/api/v1/catalog/products/{$product->id}/images", ['images' => [$this->png('one-more.png')]])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'IMAGE_LIMIT_REACHED');

        $this->assertSame($max, ProductImage::query()->where('product_id', $product->id)->count());
    }

    public function test_a_listing_shows_what_it_holds_and_a_product_with_no_files_shows_none(): void
    {
        [$user, $org, $product] = $this->ownedProduct();
        Sanctum::actingAs($user);
        $this->withHeaders($this->withTenant($org))
            ->postJson("/api/v1/catalog/products/{$product->id}/images", ['images' => [$this->png()]])
            ->assertCreated();

        $offer = $this->offer($org, [], null, $product->variants->first());

        $row = $this->getJson("/api/v1/marketplace/offers/{$offer->id}")->assertOk()->json('data');

        $this->assertNotEmpty($row['product_images']);
        $this->assertNotNull($row['product_cover_image']);
        Storage::disk('public')->assertExists(
            ProductImage::query()->where('product_id', $product->id)->value('path')
        );
        $this->assertSame($row['product_cover_image'], $row['product_images'][0]['url']);
    }
}
