<?php

namespace Tests\Feature\Platform;

use App\Core\Models\AuditLog;
use App\Inventory\Models\InventoryMovement;
use App\Marketplace\Models\Offer;
use App\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * The platform layer, as an actor and not as a screen.
 *
 * "طبقة المسؤول" only exists if the backend distinguishes the platform from the
 * businesses it hosts. Each test below asks one question a UI cannot answer on
 * its own: who may see this list, whose decision is this, and does the decision
 * change anything real? §36 forbids a permission that lives in a button, and
 * §47 forbids a review that pretends to have consulted an official register —
 * so the assertions cover both the grant and the wording.
 */
class PlatformAdminLayerTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /** @return array{0: \App\Identity\Models\User, 1: Organization} */
    protected function seller(): array
    {
        return $this->accountWithOrganization('SellerAdmin', 'seller', 'شركة البيع');
    }

    /** @return array{0: \App\Identity\Models\User, 1: Organization} */
    protected function platform(): array
    {
        return $this->accountWithOrganization('SuperAdmin', 'seller', 'منصة حصّال');
    }

    /**
     * An organization waiting for the platform's decision, with its legal
     * identifiers still missing so the requirement switches have something to
     * say about.
     */
    protected function pendingOrganization(): Organization
    {
        return $this->organization('seller', 'منشأة بانتظار المراجعة', [
            'status' => Organization::STATUS_PENDING,
            'commercial_registration_number' => null,
            'vat_number' => null,
            'kyc_verified_at' => null,
        ]);
    }

    public function test_the_sellers_shelf_holds_every_status_and_the_market_only_live_ones(): void
    {
        $this->setSetting('offer.requires_review', '1', 'boolean');

        [$user, $seller] = $this->seller();
        Sanctum::actingAs($user);

        $draft = $this->offer($seller, ['status' => Offer::STATUS_DRAFT, 'published_at' => null]);
        $awaiting = $this->offer($seller, ['status' => Offer::STATUS_PENDING_REVIEW, 'published_at' => null]);
        $paused = $this->offer($seller, ['status' => Offer::STATUS_PAUSED]);

        $shelf = $this->getJson('/api/v1/marketplace/my-offers?per_page=50', $this->withTenant($seller))
            ->assertOk()
            ->assertJsonPath('pagination.total_items', 3)
            ->json('data');

        $ids = array_column($shelf, 'id');
        sort($ids);
        $expected = [$draft->id, $awaiting->id, $paused->id];
        sort($expected);
        $this->assertSame($expected, $ids);

        // None of the three belongs on the market: the draft and the listing
        // under review were never offered, and a pause is the seller's own
        // business. Which states are public is not the seller's choice.
        $publicIds = array_column(
            $this->getJson('/api/v1/marketplace/offers?per_page=50')->json('data'),
            'id'
        );
        $this->assertSame([], array_values(array_intersect($publicIds, $expected)));

        // Publish one and the market shows exactly that one.
        $active = $this->offer($seller, ['status' => Offer::STATUS_ACTIVE]);
        $publicIds = array_column(
            $this->getJson('/api/v1/marketplace/offers?per_page=50')->json('data'),
            'id'
        );
        $this->assertSame(
            [$active->id],
            array_values(array_intersect($publicIds, array_merge($expected, [$active->id])))
        );

        // A status filter narrows the shelf; a nonsense one is refused rather
        // than quietly ignored.
        $this->getJson('/api/v1/marketplace/my-offers?status=' . Offer::STATUS_DRAFT, $this->withTenant($seller))
            ->assertOk()
            ->assertJsonPath('pagination.total_items', 1)
            ->assertJsonPath('data.0.status', Offer::STATUS_DRAFT);

        $this->getJson('/api/v1/marketplace/my-offers?status=ARCHIVED', $this->withTenant($seller))
            ->assertStatus(422);
    }

    public function test_the_shelf_is_a_tenants_view_and_not_a_global_one(): void
    {
        [, $seller] = $this->seller();
        $this->offer($seller);

        [$otherUser, $other] = $this->accountWithOrganization('SellerAdmin', 'seller', 'منشأة أخرى');
        Sanctum::actingAs($otherUser);

        // Same permission, different business: the query is anchored to the
        // tenant in context, so the other shelf stays empty.
        $this->getJson('/api/v1/marketplace/my-offers', $this->withTenant($other))
            ->assertOk()
            ->assertJsonPath('pagination.total_items', 0);

        // With no header the middleware falls back to this account's own active
        // membership, which is the same organization — so the answer must be
        // identical rather than broader.
        $this->getJson('/api/v1/marketplace/my-offers')
            ->assertOk()
            ->assertJsonPath('pagination.total_items', 0);

        // An account that belongs to no business has no shelf at all, and is
        // told so instead of being shown a list that happens to be empty. It has
        // to be a platform account to make that point: an unaffiliated user no
        // longer holds `offers.view` either, and would be refused for the wrong
        // reason — by grant, before the request ever reached the tenant rule.
        $stranger = $this->user('بلا منشأة');
        $stranger->assignRole('SuperAdmin');
        Sanctum::actingAs($stranger);

        $this->getJson('/api/v1/marketplace/my-offers')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'TENANT_REQUIRED');
    }

    public function test_the_moderation_queue_lists_other_businesses_and_refuses_the_seller(): void
    {
        [$sellerUser, $seller] = $this->seller();
        $awaiting = $this->offer($seller, [
            'status' => Offer::STATUS_PENDING_REVIEW,
            'published_at' => null,
        ]);
        $live = $this->offer($seller);

        // The seller has listings in the queue and still cannot ask for it: the
        // queue holds other businesses' unpublished offers.
        Sanctum::actingAs($sellerUser);
        $this->getJson('/api/v1/marketplace/moderation-queue', $this->withTenant($seller))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'PERMISSION_DENIED')
            ->assertJsonPath('error.details.required_permission', 'offers.moderate');

        [$platformUser] = $this->platform();
        Sanctum::actingAs($platformUser);

        $queue = $this->getJson('/api/v1/marketplace/moderation-queue?per_page=50')
            ->assertOk()
            ->json('data');

        $ids = array_column($queue, 'id');
        $this->assertContains($awaiting->id, $ids);
        $this->assertNotContains($live->id, $ids);

        // A reviewer may ask for a different state, but never for one the offer
        // vocabulary does not define.
        $this->getJson('/api/v1/marketplace/moderation-queue?status=' . Offer::STATUS_EXPIRED)
            ->assertOk();
        $this->getJson('/api/v1/marketplace/moderation-queue?status=REVIEWING')
            ->assertStatus(422);
    }

    /**
     * The console's business directory. The same URL answers two different
     * questions depending on who asks, and the `search` parameter the screen
     * sends has to actually narrow the result — a filter the server ignores
     * returns an unfiltered list that looks like a search that found nothing.
     */
    public function test_the_organization_directory_is_scoped_to_the_caller_and_honours_search(): void
    {
        [$sellerUser, $seller] = $this->seller();
        $other = $this->organization('buyer', 'مؤسسة التبغ والمشروبات', [
            'status' => Organization::STATUS_PENDING,
        ]);
        $unrelated = $this->organization('buyer', 'مطاعم الوادي');

        [$platformUser] = $this->platform();
        Sanctum::actingAs($platformUser);

        $all = $this->getJson('/api/v1/organizations?per_page=50')
            ->assertOk()
            ->json('data');
        $ids = array_column($all, 'id');
        $this->assertContains($seller->id, $ids);
        $this->assertContains($other->id, $ids);
        $this->assertContains($unrelated->id, $ids);

        $hits = $this->getJson('/api/v1/organizations?search=' . urlencode('التبغ') . '&per_page=50')
            ->assertOk()
            ->json('data');
        $hitIds = array_column($hits, 'id');
        $this->assertSame([$other->id], $hitIds);

        // Status is the other thing the console filters by, and it is the
        // platform's own review state, not a marketing label.
        $pendingIds = array_column(
            $this->getJson('/api/v1/organizations?status=' . Organization::STATUS_PENDING . '&per_page=50')
                ->assertOk()->json('data'),
            'id'
        );
        $this->assertNotContains($seller->id, $pendingIds);
        $this->assertContains($other->id, $pendingIds);

        // The same request from a business lists its own companies only.
        Sanctum::actingAs($sellerUser);
        $mine = $this->getJson('/api/v1/organizations?per_page=50')
            ->assertOk()
            ->json('data');
        $this->assertSame([$seller->id], array_column($mine, 'id'));
    }

    public function test_a_business_cannot_verify_itself(): void
    {
        [$user, $seller] = $this->seller();
        $seller->update(['status' => Organization::STATUS_PENDING]);

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/organizations/' . $seller->id . '/verification', [
            'decision' => 'VERIFY',
        ], $this->withTenant($seller) + ['X-Idempotency-Key' => 'self-verify'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'PERMISSION_DENIED')
            ->assertJsonPath('error.details.required_permission', 'organizations.verify');

        $this->assertSame(Organization::STATUS_PENDING, $seller->fresh()->status);
    }

    public function test_a_platform_verification_is_recorded_as_a_platform_review(): void
    {
        $organization = $this->pendingOrganization();

        [$platformUser] = $this->platform();
        Sanctum::actingAs($platformUser);

        $this->putJson('/api/v1/organizations/' . $organization->id . '/verification', [
            'decision' => 'VERIFY',
        ], ['X-Idempotency-Key' => 'verify-1'])
            ->assertOk()
            ->assertJsonPath('data.status', Organization::STATUS_VERIFIED)
            ->assertJsonPath('data.metadata.kyc.source', 'hassal_platform_review')
            ->assertJsonPath('data.metadata.kyc.decided_by', $platformUser->id);

        $this->assertNotNull($organization->fresh()->kyc_verified_at);

        // The wording may claim a review inside HASSAL and nothing beyond it:
        // no official register was contacted, so none may be named.
        $second = $this->pendingOrganization();
        $message = $this->putJson('/api/v1/organizations/' . $second->id . '/verification', [
            'decision' => 'VERIFY',
        ], ['X-Idempotency-Key' => 'verify-2'])->json('message');
        $this->assertStringContainsString('منصة حصّال', (string) $message);
        $this->assertStringNotContainsString('جهة رسمية', (string) $message);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'user_id' => $platformUser->id,
            'action' => 'ORGANIZATION_VERIFICATION_VERIFY',
            'auditable_type' => Organization::class,
            'auditable_id' => $organization->id,
        ]);

        // The audit row must carry the transition, not an empty snapshot: an
        // entry with no recorded before-state cannot be read back later.
        $audit = AuditLog::query()
            ->where('action', 'ORGANIZATION_VERIFICATION_VERIFY')
            ->where('auditable_id', $organization->id)
            ->firstOrFail();
        $this->assertSame(Organization::STATUS_PENDING, $audit->old_values['status'] ?? null);
        $this->assertSame(Organization::STATUS_VERIFIED, $audit->new_values['status'] ?? null);
    }

    public function test_an_adverse_decision_requires_a_reason_and_a_repeated_one_is_refused(): void
    {
        $organization = $this->pendingOrganization();

        [$platformUser] = $this->platform();
        Sanctum::actingAs($platformUser);

        foreach (['REJECT', 'SUSPEND'] as $decision) {
            $this->putJson('/api/v1/organizations/' . $organization->id . '/verification', [
                'decision' => $decision,
            ], ['X-Idempotency-Key' => strtolower($decision) . '-noreason'])
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'REASON_REQUIRED');
        }

        $this->assertSame(Organization::STATUS_PENDING, $organization->fresh()->status);

        $this->putJson('/api/v1/organizations/' . $organization->id . '/verification', [
            'decision' => 'REJECT',
            'reason' => 'السجل التجاري منتهي المدة',
        ], ['X-Idempotency-Key' => 'reject-1'])
            ->assertOk()
            ->assertJsonPath('data.status', Organization::STATUS_REJECTED)
            ->assertJsonPath('data.metadata.kyc.reason', 'السجل التجاري منتهي المدة');

        // Re-deciding is not a no-op where the decision is itself the record:
        // a second write buries the reason that explains the first.
        $this->putJson('/api/v1/organizations/' . $organization->id . '/verification', [
            'decision' => 'REJECT',
            'reason' => 'تكرار',
        ], ['X-Idempotency-Key' => 'reject-2'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'DECISION_ALREADY_APPLIED');

        // A rejection must not leave anything that reads as an approval date.
        $this->assertNull($organization->fresh()->kyc_verified_at);
    }

    public function test_a_reinstatement_does_not_redate_the_original_verification(): void
    {
        $organization = $this->pendingOrganization();

        [$platformUser] = $this->platform();
        Sanctum::actingAs($platformUser);

        $this->putJson('/api/v1/organizations/' . $organization->id . '/verification', [
            'decision' => 'VERIFY',
        ], ['X-Idempotency-Key' => 'verify-first'])->assertOk();

        $original = $organization->fresh()->kyc_verified_at;
        $this->assertNotNull($original);

        $this->putJson('/api/v1/organizations/' . $organization->id . '/verification', [
            'decision' => 'SUSPEND',
            'reason' => 'نزاع لم يُغلق',
        ], ['X-Idempotency-Key' => 'suspend-again'])->assertOk();

        $this->putJson('/api/v1/organizations/' . $organization->id . '/verification', [
            'decision' => 'REINSTATE',
        ], ['X-Idempotency-Key' => 'reinstate-again'])->assertOk();

        $this->assertSame(
            $original->toIso8601String(),
            $organization->fresh()->kyc_verified_at->toIso8601String()
        );
    }

    public function test_verification_honours_the_legal_fields_the_platform_itself_required(): void
    {
        $this->setSetting('legal.require_commercial_registration', '1', 'boolean');
        $this->setSetting('legal.require_vat_number', '1', 'boolean');

        $organization = $this->pendingOrganization();

        [$platformUser] = $this->platform();
        Sanctum::actingAs($platformUser);

        $response = $this->putJson('/api/v1/organizations/' . $organization->id . '/verification', [
            'decision' => 'VERIFY',
        ], ['X-Idempotency-Key' => 'verify-missing'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'LEGAL_FIELDS_INCOMPLETE');

        $missing = $response->json('error.details.missing_fields');
        sort($missing);
        $this->assertSame(['commercial_registration_number', 'vat_number'], $missing);
        $this->assertSame(Organization::STATUS_PENDING, $organization->fresh()->status);

        // Refusing is allowed on incomplete data; approving is not.
        $this->putJson('/api/v1/organizations/' . $organization->id . '/verification', [
            'decision' => 'REJECT',
            'reason' => 'البيانات النظامية غير مكتملة',
        ], ['X-Idempotency-Key' => 'reject-missing'])->assertOk();

        // With the requirement switched back off, the same incomplete record may
        // be approved: the guard follows the settings, not a hardcoded idea of
        // what paperwork any one country demands.
        $this->setSetting('legal.require_commercial_registration', '0', 'boolean');
        $this->setSetting('legal.require_vat_number', '0', 'boolean');

        $next = $this->pendingOrganization();
        $this->putJson('/api/v1/organizations/' . $next->id . '/verification', [
            'decision' => 'VERIFY',
        ], ['X-Idempotency-Key' => 'verify-switched-off'])->assertOk();
    }

    public function test_suspending_a_business_stops_it_trading_and_being_used(): void
    {
        [$sellerUser, $seller] = $this->seller();
        $seller->update(['status' => Organization::STATUS_PENDING]);
        $live = $this->offer($seller);

        // On the market while the business is in good standing.
        $this->assertContains($live->id, $this->publicOfferIds());

        [$platformUser] = $this->platform();
        Sanctum::actingAs($platformUser);
        $this->putJson('/api/v1/organizations/' . $seller->id . '/verification', [
            'decision' => 'SUSPEND',
            'reason' => 'بلاغ عن عرض غير مطابق',
        ], ['X-Idempotency-Key' => 'suspend-1'])->assertOk();

        // A decision that left the published listings on the shelf would have
        // been recorded and never applied.
        $this->assertNotContains($live->id, $this->publicOfferIds());

        $this->getJson('/api/v1/marketplace/offers/' . $live->id)
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        // And the tenant middleware is where the business itself stops: a
        // suspended organization cannot be placed back into context, so nothing
        // downstream can act as it, whatever its own console still shows.
        Sanctum::actingAs($sellerUser);
        $this->getJson('/api/v1/marketplace/my-offers', $this->withTenant($seller))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ORGANIZATION_SUSPENDED');

        // Reinstate it and the same listing is on the market again: the
        // withdrawal follows the organization's state and nothing else.
        Sanctum::actingAs($platformUser);
        $this->putJson('/api/v1/organizations/' . $seller->id . '/verification', [
            'decision' => 'REINSTATE',
        ], ['X-Idempotency-Key' => 'reinstate-seller'])->assertOk();

        $this->assertContains($live->id, $this->publicOfferIds());
    }

    /** @return array<int, int> */
    protected function publicOfferIds(): array
    {
        return array_column(
            $this->getJson('/api/v1/marketplace/offers?per_page=50')->json('data'),
            'id'
        );
    }

    public function test_an_adjustment_changes_the_offers_own_quantities_and_records_the_transition(): void
    {
        [$user, $seller] = $this->seller();
        $offer = $this->offer($seller, ['total_quantity' => 40, 'available_quantity' => 40]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/inventory-movements/adjust', [
            'offer_id' => $offer->id,
            'type' => InventoryMovement::TYPE_IN,
            'quantity' => 10,
            'reason' => 'وصول كمية من المورد',
        ], $this->withTenant($seller) + ['X-Idempotency-Key' => 'restock'])
            ->assertCreated()
            ->assertJsonPath('data.previous_quantity', '40.00')
            ->assertJsonPath('data.new_quantity', '50.00');

        $offer->refresh();
        $this->assertSame('50.00', (string) $offer->available_quantity);
        $this->assertSame('50.00', (string) $offer->total_quantity);

        // A movement reporting numbers nothing else agrees with is a
        // contradiction: the row must describe the change it actually caused.
        $movement = InventoryMovement::query()->where('offer_id', $offer->id)->latest('id')->firstOrFail();
        $this->assertSame('40.00', (string) $movement->previous_quantity);
        $this->assertSame('50.00', (string) $movement->new_quantity);
        $this->assertSame(Offer::class, $movement->reference_type);
        $this->assertSame($seller->id, (int) $movement->organization_id);
        $this->assertSame($user->id, (int) $movement->user_id);
        $this->assertSame($offer->warehouse_id, (int) $movement->warehouse_id);

        // Cutting below what is free is refused, and the ceiling it reports is
        // the amount actually available.
        $refusal = $this->postJson('/api/v1/inventory-movements/adjust', [
            'offer_id' => $offer->id,
            'type' => InventoryMovement::TYPE_ADJUSTED,
            'quantity' => -500,
            'reason' => 'جرد',
        ], $this->withTenant($seller) + ['X-Idempotency-Key' => 'over-cut'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'QUANTITY_INFEASIBLE');
        $this->assertSame(50.0, (float) $refusal->json('error.details.maximum_reduction'));

        $this->assertSame('50.00', (string) $offer->fresh()->available_quantity);
        $this->assertSame(1, InventoryMovement::query()->where('offer_id', $offer->id)->count());
    }

    public function test_an_adjustment_respects_committed_stock_and_other_peoples_offers(): void
    {
        [$user, $seller] = $this->seller();
        $offer = $this->offer($seller, [
            'total_quantity' => 30,
            'available_quantity' => 20,
            'reserved_quantity' => 10,
        ]);

        [, $other] = $this->accountWithOrganization('SellerAdmin', 'seller', 'منشأة أخرى');
        $foreignOffer = $this->offer($other);

        Sanctum::actingAs($user);

        // 20 free against 10 already committed: -25 would leave a buyer's
        // reservation backed by nothing.
        $this->postJson('/api/v1/inventory-movements/adjust', [
            'offer_id' => $offer->id,
            'type' => InventoryMovement::TYPE_ADJUSTED,
            'quantity' => -25,
            'reason' => 'تالف',
        ], $this->withTenant($seller) + ['X-Idempotency-Key' => 'over-cut'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'QUANTITY_INFEASIBLE');

        // An offer is addressed by id, so it must also be owned.
        $this->postJson('/api/v1/inventory-movements/adjust', [
            'offer_id' => $foreignOffer->id,
            'type' => InventoryMovement::TYPE_IN,
            'quantity' => 5,
            'reason' => 'محاولة تعديل على منشأة أخرى',
        ], $this->withTenant($seller) + ['X-Idempotency-Key' => 'foreign'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'OFFER_NOT_OWNED');

        $this->assertSame('20.00', (string) $offer->fresh()->available_quantity);
        $this->assertSame('100.00', (string) $foreignOffer->fresh()->total_quantity);
        $this->assertSame(0, InventoryMovement::query()->count(), 'A refused adjustment must not leave a row.');

        // The largest honest cut is accepted, and running out is the only
        // inventory fact a status may tell.
        $this->postJson('/api/v1/inventory-movements/adjust', [
            'offer_id' => $offer->id,
            'type' => InventoryMovement::TYPE_ADJUSTED,
            'quantity' => -20,
            'reason' => 'إتلاف بعد الجرد',
        ], $this->withTenant($seller) + ['X-Idempotency-Key' => 'cut'])
            ->assertCreated()
            ->assertJsonPath('data.new_quantity', '0.00');

        $offer->refresh();
        $this->assertSame('0.00', (string) $offer->available_quantity);
        $this->assertSame('10.00', (string) $offer->total_quantity);
        $this->assertSame(Offer::STATUS_SOLD_OUT, $offer->status);

        // A closed offer is not a ledger anyone may post to — including the
        // platform, whose token reaches across organizations.
        //
        // `forceFill`, because `status` is one of the columns the model refuses
        // mass assignment from a request: a guarded `update()` here would leave
        // the row ACTIVE and the test would pass by asserting on a state it
        // never wrote. The guard is the security fix; a test that quietly works
        // around it is not a test of it.
        $foreignOffer->forceFill(['status' => Offer::STATUS_CANCELLED])->save();
        [$platformUser] = $this->platform();
        Sanctum::actingAs($platformUser);
        $this->postJson('/api/v1/inventory-movements/adjust', [
            'offer_id' => $foreignOffer->id,
            'type' => InventoryMovement::TYPE_IN,
            'quantity' => 5,
            'reason' => 'عرض مغلق',
        ], $this->withTenant($other) + ['X-Idempotency-Key' => 'closed'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OFFER_CLOSED');
    }

    public function test_the_marketplace_closes_when_its_flag_is_off_and_reopens_immediately(): void
    {
        [$user, $seller] = $this->seller();
        $this->offer($seller);

        $this->setFlag('marketplace', false);

        Sanctum::actingAs($user);

        // The flag gates the seller's own shelf too, because that is where a
        // listing is published from; a hidden button is not enforcement (§36).
        $this->getJson('/api/v1/marketplace/my-offers', $this->withTenant($seller))
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'FEATURE_DISABLED');

        $this->setFlag('marketplace', true);

        // Turning a capability back on must be visible without waiting for a
        // cache to expire — which is why setFlag() invalidates, not only writes.
        $this->getJson('/api/v1/marketplace/my-offers', $this->withTenant($seller))
            ->assertOk()
            ->assertJsonPath('pagination.total_items', 1);
    }

    /**
     * The console offers filters over the audit trail, so the server has to
     * honour them. An ignored parameter returns the full list under a header
     * that names a narrower one, and a reader trusts the header.
     */
    public function test_the_audit_trail_filters_and_stays_inside_the_callers_scope(): void
    {
        $first = $this->pendingOrganization();
        $second = $this->pendingOrganization();

        [$platformUser] = $this->platform();
        Sanctum::actingAs($platformUser);

        $this->putJson('/api/v1/organizations/' . $first->id . '/verification', ['decision' => 'VERIFY'],
            ['X-Idempotency-Key' => 'audit-verify-1'])->assertOk();
        $this->putJson('/api/v1/organizations/' . $second->id . '/verification',
            ['decision' => 'REJECT', 'reason' => 'بيانات غير مكتملة'],
            ['X-Idempotency-Key' => 'audit-reject-1'])->assertOk();

        $verifyOnly = $this->getJson('/api/v1/audit-logs?action=ORGANIZATION_VERIFICATION_VERIFY&per_page=50')
            ->assertOk()
            ->json('data');
        $this->assertNotEmpty($verifyOnly);
        foreach ($verifyOnly as $row) {
            $this->assertSame('ORGANIZATION_VERIFICATION_VERIFY', $row['action']);
        }

        $organizations = $this->getJson('/api/v1/audit-logs?auditable_type=' . urlencode(Organization::class) . '&per_page=50')
            ->assertOk()
            ->json('data');
        foreach ($organizations as $row) {
            $this->assertSame(Organization::class, $row['auditable_type']);
        }

        // Both decisions were taken against different businesses, so the
        // platform's trail spans them; filtering to one narrows to that one.
        $forFirst = array_column(
            $this->getJson('/api/v1/audit-logs?auditable_type=' . urlencode(Organization::class)
                . '&organization_id=' . $first->id . '&per_page=50')->assertOk()->json('data'),
            'auditable_id'
        );
        $this->assertContains($first->id, $forFirst);
        $this->assertNotContains($second->id, $forFirst);

        // A business holding the audit permission reads its own trail only.
        [$auditorUser, $auditorOrg] = $this->accountWithOrganization('Auditor', 'buyer', 'شركة التدقيق');
        Sanctum::actingAs($auditorUser);

        $own = $this->getJson('/api/v1/audit-logs?per_page=50', $this->withTenant($auditorOrg))
            ->assertOk()
            ->json('data');
        foreach ($own as $row) {
            $this->assertSame($auditorOrg->id, $row['organization_id']);
        }
    }

    /**
     * The console offers assignable roles from `GET /users/roles`, and every
     * name it offers must be one `POST /users/{id}/roles` accepts. That
     * agreement is the point; the breadth is not.
     *
     * This test used to assert the opposite — that the list equalled the whole
     * `roles` table — which is how the console came to offer `SellerAdmin` under
     * a platform heading. Assigning one there wrote a global Spatie row and left
     * the person's standing inside their own company untouched, so the control
     * did not do what its label promised and the operator reported it as
     * "تغيير الدور لا ينعكس". Business standing is offered by
     * `GET /organizations/membership-roles` instead.
     */
    public function test_the_role_vocabulary_the_console_offers_is_the_one_assignment_checks(): void
    {
        [$sellerUser, $seller] = $this->seller();
        Sanctum::actingAs($sellerUser);

        $this->getJson('/api/v1/users/roles', $this->withTenant($seller))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'PERMISSION_DENIED')
            ->assertJsonPath('error.details.required_permission', 'users.manage');

        [$platformUser] = $this->platform();
        Sanctum::actingAs($platformUser);

        $names = array_column($this->getJson('/api/v1/users/roles')->assertOk()->json('data'), 'name');
        sort($names);

        $platform = config('rbac.platform_roles');
        sort($platform);

        // Only what the platform may grant, and only what really exists in the
        // `roles` table — a config name the seeder never installed would be an
        // option that fails on save.
        $this->assertSame($platform, $names);
        $this->assertSame([], array_diff($names, Role::query()->pluck('name')->all()));

        $this->assertNotContains('SellerAdmin', $names);
        $this->assertNotContains('BuyerAdmin', $names);

        // The other half of the split, offered where it means something.
        $memberRoles = array_column(
            $this->getJson('/api/v1/organizations/membership-roles')->assertOk()->json('data'),
            'value'
        );
        $this->assertContains('staff', $memberRoles);
        $this->assertNotContains('SuperAdmin', $memberRoles);
    }
}
