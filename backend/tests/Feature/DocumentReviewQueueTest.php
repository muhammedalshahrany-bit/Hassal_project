<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * The platform's desk for other people's paperwork.
 *
 * Per-organization document lists already existed, and so did the decision
 * route; what did not exist was a way for staff to see that anything is
 * waiting at all. `GET /organizations/document-queue` is that view, and it is
 * the one surface in this area where rows from several businesses appear in
 * the same list — which makes it exactly the place a tenant-isolation mistake
 * would show up.
 *
 * Three things are pinned here: a business never sees another business's row
 * (the permission, not the filter, is what stops it); the default view is the
 * work in front of a reviewer rather than the whole archive; and the counts the
 * console prints are the server's, counted over the same rows the queue pages.
 */
class DocumentReviewQueueTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    private function filed(Organization $organization, string $type = OrganizationDocument::TYPE_COMMERCIAL_REGISTRATION, string $status = OrganizationDocument::STATUS_PENDING, array $extra = []): OrganizationDocument
    {
        return OrganizationDocument::create(array_merge([
            'organization_id'   => $organization->id,
            'type'              => $type,
            'reference_number'  => 'REF-' . $organization->id . '-' . $type,
            'disk'              => 'local',
            'path'              => 'organizations/' . $organization->id . '/documents/' . $type . '.pdf',
            'original_name'     => $type . '.pdf',
            'mime_type'         => 'application/pdf',
            'size_bytes'        => 20480,
            'status'            => $status,
            'uploaded_by'       => $organization->members()->first()?->id ?? User::query()->value('id'),
        ], $extra));
    }

    public function test_the_default_queue_shows_only_waiting_papers_from_every_business(): void
    {
        [$staff] = $this->accountWithOrganization('SuperAdmin', 'admin', 'مراجع المنصة');

        $first = $this->organization('seller', 'مطاحن الأولى');
        $second = $this->organization('seller', 'مصانع الثانية');

        $waiting = $this->filed($first);
        $this->filed($second, OrganizationDocument::TYPE_TAX_CERTIFICATE);
        $this->filed($first, OrganizationDocument::TYPE_TRADE_LICENSE, OrganizationDocument::STATUS_ACCEPTED);
        $this->filed($second, OrganizationDocument::TYPE_ID_PROOF, OrganizationDocument::STATUS_REJECTED);

        Sanctum::actingAs($staff);

        $res = $this->getJson('/api/v1/organizations/document-queue')->assertOk();

        $rows = collect($res->json('data'));

        $this->assertCount(2, $rows, 'accepted and rejected copies are not work');
        $this->assertTrue($rows->contains(fn ($row) => $row['id'] === $waiting->id));
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $rows->pluck('organization.id')->unique()->values()->all()
        );

        $row = $rows->firstWhere('id', $waiting->id);
        $this->assertSame($first->name_ar, $row['organization']['name_ar']);
        $this->assertArrayHasKey('registration_no', $row['organization']);
        $this->assertIsInt($row['waiting_hours']);
        // The paper itself is not on disk in a test; the link is allowed to be
        // absent, but the row must still carry its detected type.
        $this->assertSame('application/pdf', $row['mime_type']);
    }

    public function test_a_business_cannot_read_the_desk_even_though_it_holds_its_own_papers(): void
    {
        [$seller, $organization] = $this->accountWithOrganization('SellerAdmin', 'seller', 'منشأة منافسة');
        $other = $this->organization('seller', 'منشأة أخرى');

        $this->filed($organization);
        $this->filed($other);

        Sanctum::actingAs($seller);

        // Not "their rows only" — the whole endpoint is refused, because a
        // filtered list would still leak that other businesses have filed.
        $this->getJson('/api/v1/organizations/document-queue')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'PERMISSION_DENIED');

        $this->getJson('/api/v1/organizations/document-summary')
            ->assertForbidden();

        // Their own window still works; the refusal is about the desk, not the
        // business's right to read what it filed.
        $this->withHeaders($this->withTenant($organization))
            ->getJson("/api/v1/organizations/{$organization->id}/documents")
            ->assertOk()
            ->assertJsonCount(1, 'data.documents');
    }

    public function test_the_queue_filters_by_paper_type_by_business_and_by_name(): void
    {
        [$staff, $home] = $this->accountWithOrganization('SuperAdmin', 'admin', 'مراجع');

        $north = $this->organization('seller', 'شركة الشمال للتغليف');
        $south = $this->organization('seller', 'شركة الجنوب للحبوب');

        $this->filed($north, OrganizationDocument::TYPE_COMMERCIAL_REGISTRATION);
        $this->filed($north, OrganizationDocument::TYPE_TAX_CERTIFICATE);
        $this->filed($south, OrganizationDocument::TYPE_COMMERCIAL_REGISTRATION);

        Sanctum::actingAs($staff);
        $this->withHeaders($this->withTenant($home));

        $this->getJson('/api/v1/organizations/document-queue?type=' . OrganizationDocument::TYPE_TAX_CERTIFICATE)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.organization_id', $north->id);

        $this->getJson('/api/v1/organizations/document-queue?organization_id=' . $south->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.organization.id', $south->id);

        $this->getJson('/api/v1/organizations/document-queue?search=' . urlencode('الجنوب'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.organization.name_ar', $south->name_ar);

        // A status filter that names a decided row surfaces it, so the decision
        // a reviewer recorded stays readable after it leaves the default view.
        $decided = $this->filed($south, OrganizationDocument::TYPE_TRADE_LICENSE, OrganizationDocument::STATUS_ACCEPTED);

        $this->getJson('/api/v1/organizations/document-queue?status=accepted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $decided->id);
    }

    public function test_accepting_a_paper_moves_it_out_of_the_queue_and_shows_on_the_business_row(): void
    {
        [$staff] = $this->accountWithOrganization('SuperAdmin', 'admin', 'مراجع المنصة');
        $applicant = $this->organization('seller', 'مخبز الحي');

        $document = $this->filed($applicant, OrganizationDocument::TYPE_COMMERCIAL_REGISTRATION);
        $taxPaper = $this->filed($applicant, OrganizationDocument::TYPE_TAX_CERTIFICATE);

        Sanctum::actingAs($staff);
        $headers = $this->withTenant($applicant);

        $this->withHeaders($headers + ['X-Idempotency-Key' => 'accept-cr'])
            ->putJson("/api/v1/organizations/{$applicant->id}/documents/{$document->id}/review", ['action' => 'accept'])
            ->assertOk()
            ->assertJsonPath('data.status', OrganizationDocument::STATUS_ACCEPTED);

        $remaining = $this->getJson('/api/v1/organizations/document-queue')
            ->assertOk();

        $this->assertCount(1, $remaining->json('data'));
        $row = $remaining->json('data.0');
        $this->assertSame($taxPaper->id, $row['id']);
        $this->assertEquals([OrganizationDocument::TYPE_COMMERCIAL_REGISTRATION], $row['organization']['accepted_types']);

        // A rejection that arrives without a reason is refused: the applicant
        // has to be told which paper failed.
        $this->withHeaders($headers + ['X-Idempotency-Key' => 'reject-no-reason'])
            ->putJson("/api/v1/organizations/{$applicant->id}/documents/{$taxPaper->id}/review", ['action' => 'reject'])
            ->assertStatus(422);
    }

    public function test_the_summary_counts_the_same_rows_the_queue_pages(): void
    {
        [$staff] = $this->accountWithOrganization('SuperAdmin', 'admin', 'مراجع');

        $a = $this->organization('seller', 'منشأة أ');
        $b = $this->organization('seller', 'منشأة ب');
        $c = $this->organization('buyer', 'منشأة ج');

        $this->filed($a);
        $this->filed($b);
        $this->filed($c, OrganizationDocument::TYPE_COMMERCIAL_REGISTRATION, OrganizationDocument::STATUS_ACCEPTED);

        Sanctum::actingAs($staff);

        $summary = $this->getJson('/api/v1/organizations/document-summary')->assertOk();

        $this->assertSame(2, $summary->json('data.by_status.pending'));
        $this->assertSame(1, $summary->json('data.by_status.accepted'));
        $this->assertSame(0, $summary->json('data.by_status.rejected'));
        $this->assertSame(2, $summary->json('data.organizations_with_pending_documents'));

        $queue = $this->getJson('/api/v1/organizations/document-queue')->assertOk();
        $this->assertSame(
            $summary->json('data.by_status.pending'),
            $queue->json('pagination.total_items')
        );
    }

    public function test_a_queue_row_carries_the_business_state_the_decision_is_made_against(): void
    {
        [$staff] = $this->accountWithOrganization('SuperAdmin', 'admin', 'مراجع');
        $applicant = $this->organization('seller', 'منشأة بانتظار', [
            'status' => Organization::STATUS_PENDING,
            'commercial_registration_number' => 'CR-77001',
        ]);

        $this->filed($applicant);

        Sanctum::actingAs($staff);

        $row = $this->getJson('/api/v1/organizations/document-queue')
            ->assertOk()
            ->assertJsonPath('data.0.organization.status', Organization::STATUS_PENDING)
            ->assertJsonPath('data.0.organization.registration_no', 'CR-77001')
            ->json('data.0');

        // Nothing here says a register was consulted: the queue exposes the
        // number the business typed and the platform's own review timestamp,
        // which is empty because no one has ruled on the business yet.
        $this->assertNull($row['organization']['kyc_verified_at']);
    }
}
