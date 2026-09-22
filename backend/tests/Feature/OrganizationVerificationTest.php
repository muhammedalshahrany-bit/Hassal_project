<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Organization;
use App\Models\OrganizationDocument;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * What a business may do before a reviewer has read its paperwork.
 *
 * The rule this file proves is the one the whole trust model rests on: a
 * commercial action — publishing a listing, asking for a deal, opening a
 * dispute — is a claim made by a named company, and until this platform's staff
 * have read that company's registration papers, the counterparty is being asked
 * to trust a string somebody typed. Hiding the button is a UI preference; the
 * refusal has to come from the backend (§36).
 *
 * The other half of the rule matters just as much: an applicant must be able to
 * reach the exit. Browsing, editing the company's own record, filing documents,
 * inviting members and managing a warehouse all stay open, or the gate would
 * enforce onboarding by preventing onboarding.
 *
 * "Verified" here means a staff member accepted the filed copy. Nothing in this
 * codebase queries an official register, so nothing here may imply one either.
 */
class OrganizationVerificationTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // `local` because that is where filed papers land: private disk, no
        // public path. The assertions below read the copy back from there.
        Storage::fake('local');
        $this->seedPlatformDefaults();
    }

    /** @return array{0: User, 1: Organization} a seller whose papers nobody has read */
    protected function applicant(): array
    {
        $org = $this->organization('seller', 'مصنع لم يُراجع', [
            'status' => Organization::STATUS_PENDING,
        ]);
        $user = $this->user('مسؤول المصنع');
        $this->membership($org, $user, 'owner');
        $user->assignRole('SellerAdmin');

        return [$user, $org];
    }

    /** @return array{0: User, 1: Organization} a buyer whose papers nobody has read */
    protected function buyerApplicant(): array
    {
        $org = $this->organization('buyer', 'محل لم يُراجع', [
            'status' => Organization::STATUS_PENDING,
        ]);
        $user = $this->user('مسؤول المحل');
        $this->membership($org, $user, 'owner');
        $user->assignRole('BuyerAdmin');

        return [$user, $org];
    }

    /** A complete, valid offer payload for the given warehouse and variant. */
    protected function offerPayload(Warehouse $warehouse, int $variantId): array
    {
        return [
            'warehouse_id'       => $warehouse->id,
            'product_variant_id' => $variantId,
            'title_ar'           => 'أرز حبة كاملة',
            'opportunity_reason' => Offer::REASON_SURPLUS_STOCK,
            'min_order_quantity' => 1,
            'total_quantity'     => 40,
            'unit'               => 'carton',
            'unit_price'         => 24500,
            'currency_code'      => 'YER',
        ];
    }

    // ── The gate ────────────────────────────────────────────────────────────

    public function test_an_unreviewed_business_can_browse_but_cannot_publish(): void
    {
        [$user, $org] = $this->applicant();
        $warehouse = $this->warehouse($org);
        $variant = $this->productVariant($org);

        // Another business, already reviewed, with a live listing to look at.
        $published = $this->offer($this->organization('seller', 'شركة مراجعة'));

        Sanctum::actingAs($user);
        $headers = $this->withTenant($org);

        $listed = $this->withHeaders($headers)->getJson('/api/v1/marketplace/offers')
            ->assertOk()
            ->json('data');

        $this->assertContains($published->id, array_column($listed, 'id'));

        $this->withHeaders($headers)
            ->postJson('/api/v1/marketplace/offers', $this->offerPayload($warehouse, $variant->id))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ORGANIZATION_NOT_VERIFIED')
            ->assertJsonPath('error.details.required_action', 'COMPLETE_ORGANIZATION_VERIFICATION')
            ->assertJsonPath('error.details.organization_status', Organization::STATUS_PENDING);

        $this->assertSame(0, Offer::query()->where('seller_organization_id', $org->id)->count());
    }

    public function test_the_refusal_says_which_step_is_missing_instead_of_saying_no(): void
    {
        [$user, $org] = $this->applicant();
        $org->update(['commercial_registration_number' => null, 'vat_number' => null]);

        $this->setSetting('legal.require_commercial_registration', true, 'boolean');
        $this->setSetting('legal.require_vat_number', true, 'boolean');

        $warehouse = $this->warehouse($org);
        $variant = $this->productVariant($org);

        Sanctum::actingAs($user);
        $checklist = $this->withHeaders($this->withTenant($org))
            ->postJson('/api/v1/marketplace/offers', $this->offerPayload($warehouse, $variant->id))
            ->assertStatus(403)
            ->json('error.details.checklist');

        $byKey = collect($checklist)->keyBy('key');

        $this->assertTrue($byKey['commercial_registration_number']['required']);
        $this->assertFalse($byKey['commercial_registration_number']['done']);
        $this->assertFalse($byKey['accepted_documents']['done']);

        // Filing the papers moves the checklist without opening the gate: the
        // decision is a human's, and an upload is not a verdict.
        $org->update(['commercial_registration_number' => 'CR-99001', 'vat_number' => 'VAT-99001']);

        $this->assertTrue(
            collect($this->withHeaders($this->withTenant($org))
                ->postJson('/api/v1/marketplace/offers', $this->offerPayload($warehouse, $variant->id))
                ->assertStatus(403)
                ->json('error.details.checklist'))
                ->keyBy('key')['commercial_registration_number']['done']
        );
    }

    public function test_the_gate_leaves_the_exit_open(): void
    {
        [$user, $org] = $this->applicant();

        Sanctum::actingAs($user);
        $headers = $this->withTenant($org);

        $this->withHeaders($headers)->postJson('/api/v1/warehouses', [
            'name'         => 'مستودع عدن',
            'city'         => self::CITY,
            'address_line' => 'شارع التسعين',
            'latitude'     => self::LAT,
            'longitude'    => self::LNG,
        ])->assertCreated();

        $this->withHeaders($headers)->postJson("/api/v1/organizations/{$org->id}/documents", [
            'type' => OrganizationDocument::TYPE_COMMERCIAL_REGISTRATION,
            'file' => UploadedFile::fake()->create('cr.pdf', 20, 'application/pdf'),
        ])->assertCreated();

        $this->withHeaders($headers)->patchJson('/api/v1/auth/profile', [
            'name' => 'مسؤول المصنع',
        ])->assertOk();

        $this->withHeaders($headers)->getJson("/api/v1/organizations/{$org->id}")
            ->assertOk();
    }

    public function test_verification_is_an_operator_switch_not_a_constant(): void
    {
        [$user, $org] = $this->applicant();
        $warehouse = $this->warehouse($org);
        $variant = $this->productVariant($org);

        Sanctum::actingAs($user);
        $headers = $this->withTenant($org);

        $this->setSetting('marketplace.require_verified_to_trade', false, 'boolean');

        $this->withHeaders($headers)
            ->postJson('/api/v1/marketplace/offers', $this->offerPayload($warehouse, $variant->id))
            ->assertCreated();

        $this->setSetting('marketplace.require_verified_to_trade', true, 'boolean');

        $this->withHeaders($headers)
            ->postJson('/api/v1/marketplace/offers', $this->offerPayload($warehouse, $variant->id))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ORGANIZATION_NOT_VERIFIED');
    }

    public function test_a_reviewed_business_trades_and_the_gate_does_not_reopen_for_its_existing_work(): void
    {
        [$user, $org] = $this->accountWithOrganization('SellerAdmin', 'seller', 'شركة موثقة');
        $warehouse = $this->warehouse($org);
        $variant = $this->productVariant($org);

        Sanctum::actingAs($user);
        $headers = $this->withTenant($org);

        $this->withHeaders($headers)
            ->postJson('/api/v1/marketplace/offers', $this->offerPayload($warehouse, $variant->id))
            ->assertCreated();

        // A listing that is already in front of buyers.
        $live = $this->offer($org, ['status' => Offer::STATUS_ACTIVE]);

        // A business that later falls out of good standing must still be able to
        // pause what it already published; refusing that would hold its own
        // shelf hostage and punish the buyers already looking at it.
        $org->update(['status' => Organization::STATUS_PENDING]);

        $this->withHeaders($headers)->putJson("/api/v1/marketplace/offers/{$live->id}/status", [
            'status' => Offer::STATUS_PAUSED,
            'reason' => 'توقف مؤقت',
        ])->assertOk();

        // ... while starting new commercial conversations is refused again.
        $this->withHeaders($headers)
            ->postJson('/api/v1/marketplace/offers', $this->offerPayload($warehouse, $variant->id))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ORGANIZATION_NOT_VERIFIED');

        // A buyer role, so the refusal below can only be the gate: the same
        // request from a seller account would be answered by the permission
        // middleware first and would prove nothing.
        [$buyer, $buyerOrg] = $this->buyerApplicant();
        Sanctum::actingAs($buyer);

        $this->withHeaders($this->withTenant($buyerOrg))->postJson('/api/v1/demands', [
            'title_ar'        => 'طلب شراء أرز',
            'target_quantity' => 30,
            'unit'            => 'carton',
            'currency_code'   => 'YER',
            'delivery_city'   => self::CITY,
        ])->assertStatus(403)->assertJsonPath('error_code', 'ORGANIZATION_NOT_VERIFIED');
    }

    public function test_platform_staff_are_not_brought_before_the_gate(): void
    {
        [$staff, $staffOrg] = $this->accountWithOrganization('SuperAdmin', 'seller', 'منصة حصّال');
        $applicant = $this->organization('seller', 'مصنع لم يُراجع', ['status' => Organization::STATUS_PENDING]);
        $warehouse = $this->warehouse($applicant);
        $variant = $this->productVariant($applicant);

        Sanctum::actingAs($staff);

        // Staff acting *for* the pending business, which the tenant middleware
        // allows them to do without a membership. The gate is the only thing
        // left standing between this request and a published listing, and it
        // must not stop the platform from doing its own job on someone's behalf.
        $this->withHeaders($this->withTenant($applicant))
            ->postJson('/api/v1/marketplace/offers', $this->offerPayload($warehouse, $variant->id))
            ->assertCreated();

        $this->withHeaders($this->withTenant($staffOrg))
            ->getJson("/api/v1/organizations/{$applicant->id}/documents")
            ->assertOk();
    }

    // ── The paperwork the decision is made from ─────────────────────────────

    public function test_a_rival_business_cannot_read_another_business_documents(): void
    {
        [$applicant, $org] = $this->applicant();
        $this->fileDocument($applicant, $org, OrganizationDocument::TYPE_COMMERCIAL_REGISTRATION, 'CR-77881');

        [$rival, $rivalOrg] = $this->accountWithOrganization('SellerAdmin', 'seller', 'منشأة منافسة');
        Sanctum::actingAs($rival);
        $headers = $this->withTenant($rivalOrg);

        // Same permission, different company: what refuses here is ownership.
        $this->withHeaders($headers)
            ->getJson("/api/v1/organizations/{$org->id}/documents")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->withHeaders($headers)
            ->getJson("/api/v1/organizations/{$rivalOrg->id}/documents")
            ->assertOk()
            ->assertJsonCount(0, 'data.documents');
    }

    public function test_filing_then_accepting_a_document_is_recorded_against_a_named_reviewer(): void
    {
        [$applicant, $org] = $this->applicant();
        $document = $this->fileDocument($applicant, $org, OrganizationDocument::TYPE_COMMERCIAL_REGISTRATION, 'CR-44120');

        $this->assertSame(OrganizationDocument::STATUS_PENDING, $document->status);
        $this->assertNull($document->reviewed_by);

        [$staff, $staffOrg] = $this->accountWithOrganization('SuperAdmin', 'seller', 'منصة حصّال');
        Sanctum::actingAs($staff);

        $this->withHeaders($this->withTenant($staffOrg))
            ->getJson("/api/v1/organizations/{$org->id}/documents")
            ->assertOk()
            ->assertJsonPath('data.documents.0.reference_number', 'CR-44120');

        $this->withHeaders($this->withTenant($staffOrg))
            ->putJson("/api/v1/organizations/{$org->id}/documents/{$document->id}/review", ['action' => 'accept'])
            ->assertOk()
            ->assertJsonPath('data.status', OrganizationDocument::STATUS_ACCEPTED)
            ->assertJsonPath('data.reviewed_by_name', $staff->name);

        // An accepted paper is not silently re-filed: the queue would then show
        // a decision that has already been made.
        Sanctum::actingAs($applicant);
        $this->withHeaders($this->withTenant($org))
            ->postJson("/api/v1/organizations/{$org->id}/documents", [
                'type' => OrganizationDocument::TYPE_COMMERCIAL_REGISTRATION,
                'file' => UploadedFile::fake()->create('again.pdf', 20, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'DOCUMENT_ALREADY_ACCEPTED');

        // And a decided document cannot be withdrawn by its owner.
        $this->withHeaders($this->withTenant($org))
            ->deleteJson("/api/v1/organizations/{$org->id}/documents/{$document->id}")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'DOCUMENT_ALREADY_REVIEWED');
    }

    public function test_a_rejection_carries_a_reason_the_applicant_can_act_on(): void
    {
        [$applicant, $org] = $this->applicant();
        $document = $this->fileDocument($applicant, $org, OrganizationDocument::TYPE_TAX_CERTIFICATE);

        [$staff, $staffOrg] = $this->accountWithOrganization('SuperAdmin', 'seller', 'منصة حصّال');
        Sanctum::actingAs($staff);

        $this->withHeaders($this->withTenant($staffOrg))
            ->putJson("/api/v1/organizations/{$org->id}/documents/{$document->id}/review", ['action' => 'reject'])
            ->assertStatus(422);

        $this->withHeaders($this->withTenant($staffOrg))
            ->putJson("/api/v1/organizations/{$org->id}/documents/{$document->id}/review", [
                'action' => 'reject',
                'reason' => 'الصورة غير مقروءة، أعد رفع نسخة أوضح',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OrganizationDocument::STATUS_REJECTED);

        // Rejecting a paper is not the same decision as suspending the business,
        // so the gate keeps its original wording and the applicant can re-file.
        $this->assertSame(Organization::STATUS_PENDING, $org->fresh()->status);

        Sanctum::actingAs($applicant);
        $replacement = $this->fileDocument($applicant, $org, OrganizationDocument::TYPE_TAX_CERTIFICATE);
        $this->assertSame(OrganizationDocument::STATUS_PENDING, $replacement->status);
        // A rejected copy is replaced, not stacked: one open question, one row.
        $this->assertSame(1, OrganizationDocument::query()
            ->where('organization_id', $org->id)
            ->where('type', OrganizationDocument::TYPE_TAX_CERTIFICATE)
            ->where('status', OrganizationDocument::STATUS_PENDING)
            ->count());
    }

    public function test_a_business_cannot_accept_its_own_paperwork(): void
    {
        [$applicant, $org] = $this->applicant();
        $document = $this->fileDocument($applicant, $org, OrganizationDocument::TYPE_TRADE_LICENSE);

        Sanctum::actingAs($applicant);

        $this->withHeaders($this->withTenant($org))
            ->putJson("/api/v1/organizations/{$org->id}/documents/{$document->id}/review", ['action' => 'accept'])
            ->assertForbidden();

        $this->assertSame(OrganizationDocument::STATUS_PENDING, $document->fresh()->status);
    }

    /**
     * A reviewer who is not the platform owner.
     *
     * `organizations.verify` is what `review()` demands of anybody who is not a
     * SuperAdmin, so a staff role holding it must also be able to open the copy
     * it is being asked about. No role shipped in `config/rbac.php` holds this
     * permission but the owner's, which is why the role is created here rather
     * than named in the matrix — the day an operator adds a reviewing desk, this
     * is the behaviour they should expect.
     */
    public function test_a_staff_reviewer_can_read_the_paperwork_it_is_asked_to_decide(): void
    {
        [$applicant, $org] = $this->applicant();
        $document = $this->fileDocument($applicant, $org, OrganizationDocument::TYPE_COMMERCIAL_REGISTRATION, 'CR-90001');

        // `web`, the guard RolesAndPermissionsSeeder writes every permission
        // under; a role left to the app default would look in `sanctum` and
        // find no permissions at all.
        $reviewingDesk = Role::create(['name' => 'DocumentReviewer', 'guard_name' => 'web']);
        $reviewingDesk->givePermissionTo('organizations.verify');

        [$reviewer, $reviewerOrg] = $this->accountWithOrganization('SellerAdmin', 'seller', 'منضدة المراجعة');
        $reviewer->assignRole('DocumentReviewer');
        Sanctum::actingAs($reviewer);
        $headers = $this->withTenant($reviewerOrg);

        $this->withHeaders($headers)
            ->getJson("/api/v1/organizations/{$org->id}/documents")
            ->assertOk()
            ->assertJsonPath('data.documents.0.reference_number', 'CR-90001');

        $this->withHeaders($headers)
            ->putJson("/api/v1/organizations/{$org->id}/documents/{$document->id}/review", ['action' => 'accept'])
            ->assertOk()
            ->assertJsonPath('data.status', OrganizationDocument::STATUS_ACCEPTED)
            ->assertJsonPath('data.reviewed_by_name', $reviewer->name);
    }

    /**
     * Where the paper sits, and how it may be read back.
     *
     * A forwarded link is the realistic leak for filed legal records — chat,
     * browser history, a proxy log — so the guard is that the link expires and
     * that no unsigned form of it works at all.
     */
    public function test_a_filed_paper_lives_on_the_private_disk_and_reads_back_only_through_its_signed_link(): void
    {
        [$applicant, $org] = $this->applicant();
        $document = $this->fileDocument($applicant, $org, OrganizationDocument::TYPE_TAX_CERTIFICATE, 'TAX-3321');

        $this->assertSame('local', $document->disk);
        Storage::disk('local')->assertExists($document->path);

        Sanctum::actingAs($applicant);
        $headers = $this->withTenant($org);

        $link = $this->withHeaders($headers)
            ->getJson("/api/v1/organizations/{$org->id}/documents")
            ->assertOk()
            ->assertJsonPath('data.documents.0.id', $document->id)
            ->json('data.documents.0.url');

        // A public-disk path would be permanent; this must carry an expiry and
        // a signature, which is what makes a leaked link a time-bounded one.
        $this->assertStringContainsString('expires=', $link);
        $this->assertStringContainsString('signature=', $link);
        $this->assertStringNotContainsString('/storage/', $link);

        $this->get($link)->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $tampered = preg_replace('/[?&]signature=[^&]*/', '', $link);
        $this->get($tampered)->assertForbidden();
    }

    /**
     * The identity card is two papers, not one.
     *
     * A person photographs the front at the counter and the back a second later,
     * and the plausible outcome is that one shot is legible and the other is not.
     * If the two sides shared a row, the reviewer would have to accept both or
     * reject both, and the applicant would re-shoot a perfectly readable side.
     * So this asserts the pair is stored and decided separately — and, per §7,
     * that nothing about the row reads as an identity confirmed elsewhere: the
     * fields are the file and the reviewer's own decision, nothing more.
     */
    public function test_both_card_sides_are_filed_as_photos_and_ruled_on_separately(): void
    {
        [$applicant, $org] = $this->applicant();
        $staff = $this->user('مراجع المنصة');
        $staff->assignRole('SuperAdmin');

        $front = $this->filePhoto($applicant, $org, OrganizationDocument::TYPE_ID_CARD_FRONT);
        $back  = $this->filePhoto($applicant, $org, OrganizationDocument::TYPE_ID_CARD_BACK);

        $this->assertNotSame($front->id, $back->id);
        $this->assertSame(OrganizationDocument::STATUS_PENDING, $front->status);
        $this->assertSame(OrganizationDocument::STATUS_PENDING, $back->status);

        // A photograph must survive the same mime gate a scan does; the client
        // sends JPEG straight from the camera, so refusing it would be a bug.
        $this->assertSame('image/jpeg', $front->mime_type);
        Storage::disk('local')->assertExists($front->path);

        Sanctum::actingAs($staff);
        $headers = $this->withTenant($org);

        $this->withHeaders($headers)
            ->putJson("/api/v1/organizations/{$org->id}/documents/{$front->id}/review", ['action' => 'accept'])
            ->assertOk();

        $this->assertSame(OrganizationDocument::STATUS_ACCEPTED, $front->fresh()->status);
        $this->assertSame(OrganizationDocument::STATUS_PENDING, $back->fresh()->status,
            'Accepting the front must not silently accept the back.');

        $payload = $this->withHeaders($headers)
            ->getJson("/api/v1/organizations/{$org->id}/documents")
            ->assertOk()
            ->json();

        $byType = collect($payload['data']['documents'])->keyBy('type');
        $this->assertSame('accepted', $byType[OrganizationDocument::TYPE_ID_CARD_FRONT]['status']);
        $this->assertSame('pending', $byType[OrganizationDocument::TYPE_ID_CARD_BACK]['status']);

        // `accepted_types` is what the "still missing" line reads, so a half-read
        // card must not report the pair as complete.
        $this->assertContains(OrganizationDocument::TYPE_ID_CARD_FRONT, $payload['data']['accepted_types']);
        $this->assertNotContains(OrganizationDocument::TYPE_ID_CARD_BACK, $payload['data']['accepted_types']);
    }

    protected function filePhoto(User $user, Organization $org, string $type): OrganizationDocument
    {
        Sanctum::actingAs($user);

        $id = $this->withHeaders($this->withTenant($org))
            ->postJson("/api/v1/organizations/{$org->id}/documents", [
                'type' => $type,
                'file' => UploadedFile::fake()->image('card.jpg', 1200, 800),
            ])
            ->assertCreated()
            ->json('data.id');

        return OrganizationDocument::findOrFail($id);
    }

    protected function fileDocument(User $user, Organization $org, string $type, ?string $reference = null): OrganizationDocument
    {
        Sanctum::actingAs($user);

        $id = $this->withHeaders($this->withTenant($org))
            ->postJson("/api/v1/organizations/{$org->id}/documents", array_filter([
                'type'             => $type,
                'reference_number' => $reference,
                'file'             => UploadedFile::fake()->create('paper.pdf', 20, 'application/pdf'),
            ]))
            ->assertCreated()
            ->json('data.id');

        return OrganizationDocument::findOrFail($id);
    }
}
