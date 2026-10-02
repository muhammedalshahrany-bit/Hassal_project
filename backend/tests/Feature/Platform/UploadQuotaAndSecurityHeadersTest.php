<?php

namespace Tests\Feature\Platform;

use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationDocument;
use App\Catalog\Models\Product;
use App\Identity\Models\User;
use App\Platform\Services\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * The tenant storage ceiling and the download headers that go with it.
 *
 * Two facts these tests hold the code to:
 *
 *   1. `uploads.quota_bytes_per_organization` is a real ceiling, not decoration.
 *      It is one number in the settings console; if nothing read it, an operator
 *      who set it would be lied to. The ceiling spans BOTH a business's product
 *      photographs (public disk) and its filed legal papers (private disk),
 *      because counting only one lets the other fill up while the quota still
 *      reads as free. A refused upload must leave the bytes already on disk
 *      untouched — a quota answer is not a reason to delete the registration
 *      certificate that was there before it.
 *   2. Every response says `X-Content-Type-Options: nosniff`. The upload paths
 *      already derive a stored file's type from the bytes detected, never from
 *      the sender's filename; nosniff is the matching guarantee on the way out,
 *      and it matters most on the signed document stream, where a copy of a
 *      certificate is handed straight to a browser.
 *
 * Zero means no ceiling, and that operator choice is asserted too rather than
 * being overwritten by a guessed number.
 */
class UploadQuotaAndSecurityHeadersTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seedPlatformDefaults();
    }

    private function quota(int|float|string $bytes): void
    {
        app(SystemSettingsService::class)->set(
            'uploads.quota_bytes_per_organization',
            (string) $bytes,
            'integer',
            'uploads'
        );
    }

    /** @return array{0: User, 1: Organization, 2: Product} */
    private function ownedProduct(): array
    {
        [$user, $org] = $this->accountWithOrganization('SellerAdmin', 'seller');
        $variant = $this->productVariant($org);

        return [$user, $org, $variant->product];
    }

    public function test_a_photograph_over_the_tenant_quota_is_refused_and_nothing_is_written(): void
    {
        [$user, $org, $product] = $this->ownedProduct();
        Sanctum::actingAs($user);
        $this->withTenant($org);

        // 1 MB of headroom, a 2 MB file: the only reason this can be refused is
        // the tenant ceiling, since it sits under the per-file machine limit.
        $this->quota(1 * 1024 * 1024);

        $response = $this->postJson("/api/v1/catalog/products/{$product->id}/images", [
            'images' => [UploadedFile::fake()->image('big.png', 1200, 900)->size(2048)],
        ]);

        $response->assertStatus(413)->assertJsonPath('error.code', 'UPLOAD_QUOTA_EXCEEDED');
        $this->assertSame(0, $product->images()->count(), 'a refused upload must store nothing');
    }

    public function test_a_photograph_within_the_quota_is_accepted(): void
    {
        [$user, $org, $product] = $this->ownedProduct();
        Sanctum::actingAs($user);
        $this->withTenant($org);

        $this->quota(20 * 1024 * 1024);

        $this->postJson("/api/v1/catalog/products/{$product->id}/images", [
            'images' => [UploadedFile::fake()->image('ok.png', 640, 480)],
        ])->assertCreated();

        $this->assertSame(1, $product->images()->count());
    }

    public function test_a_zero_quota_leaves_storage_unmetered(): void
    {
        [$user, $org, $product] = $this->ownedProduct();
        Sanctum::actingAs($user);
        $this->withTenant($org);

        $this->quota(0);

        $this->postJson("/api/v1/catalog/products/{$product->id}/images", [
            'images' => [UploadedFile::fake()->image('any.png', 800, 600)],
        ])->assertCreated();
    }

    public function test_the_quota_counts_already_stored_bytes_before_adding_a_document(): void
    {
        [$user, $org] = $this->accountWithOrganization('SellerAdmin', 'seller');
        Sanctum::actingAs($user);
        $this->withTenant($org);

        // Half the ceiling is already used by an accepted image row.
        $this->quota(2 * 1024 * 1024);
        $variant = $this->productVariant($org);
        $this->postJson("/api/v1/catalog/products/{$variant->product->id}/images", [
            'images' => [UploadedFile::fake()->image('used.png', 1000, 800)->size(1024)],
        ])->assertCreated();

        // A 2 MB document now pushes past the ceiling that the image already
        // spent, so it is the document that is refused — the quota is shared.
        $before = OrganizationDocument::query()->where('organization_id', $org->id)->count();

        $this->postJson("/api/v1/organizations/{$org->id}/documents", [
            'type' => 'commercial_registration',
            'file' => UploadedFile::fake()->image('cr.png', 1200, 900)->size(2048),
        ])->assertStatus(413)->assertJsonPath('error.code', 'UPLOAD_QUOTA_EXCEEDED');

        $this->assertSame($before, OrganizationDocument::query()->where('organization_id', $org->id)->count());
    }

    public function test_a_signed_document_stream_declares_nosniff(): void
    {
        [$user, $org] = $this->accountWithOrganization('SellerAdmin', 'seller');
        Sanctum::actingAs($user);
        $this->withTenant($org);

        $this->quota(50 * 1024 * 1024);

        $created = $this->postJson("/api/v1/organizations/{$org->id}/documents", [
            'type' => 'commercial_registration',
            'file' => UploadedFile::fake()->image('cr.png', 900, 600),
        ])->assertCreated()->json('data.id');

        $document = OrganizationDocument::findOrFail($created);
        $signed = $document->url();
        $this->assertNotNull($signed, 'a filed paper must expose a signed read link');

        $this->get($signed)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_every_api_response_carries_the_security_headers(): void
    {
        [$user, $org] = $this->accountWithOrganization('SellerAdmin', 'seller');
        Sanctum::actingAs($user);
        $this->withTenant($org);

        $this->getJson("/api/v1/organizations/{$org->id}/documents")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'same-origin');
    }
}
