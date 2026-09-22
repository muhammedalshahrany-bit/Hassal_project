<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Organization;
use App\Models\OrganizationDocument;
use App\Support\WaitingTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * How long a row has waited must never be a negative number of hours.
 *
 * Both review desks print "ساعة انتظار" beside each row so a reviewer can
 * triage oldest-first. Carbon 3 changed `diffInMinutes()` to return a SIGNED
 * difference, which inverted the arithmetic both queues used
 * (`now()->diffInMinutes($createdAt)` — negative once the row is in the past),
 * and `floor()` of a negative fraction rounds away from zero. The result was
 * that a paper filed fourteen minutes ago was reported as `-1` hours, and the
 * moderation queue reported a growing negative number for anything older than
 * an hour. Nothing else in either response changed, so the bug was invisible to
 * every assertion written before it.
 *
 * These tests pin the three properties the label actually claims: a fresh row
 * is zero, an older row counts the hours that passed, and no row is ever below
 * zero — including when a replica's clock puts the timestamp slightly ahead.
 */
class WaitingHoursReportedTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    public function test_hours_since_counts_elapsed_time_forward_from_the_older_moment(): void
    {
        $this->assertNull(WaitingTime::hoursSince(null), 'a missing timestamp is not a wait of zero hours');
        $this->assertSame(0, WaitingTime::hoursSince(now()), 'a row filed seconds ago has not waited an hour');
        $this->assertSame(1, WaitingTime::hoursSince(now()->subMinutes(90)));
        $this->assertSame(26, WaitingTime::hoursSince(now()->subHours(26)->subMinutes(40)));
    }

    public function test_a_clock_that_runs_ahead_is_reported_as_no_wait_rather_than_negative(): void
    {
        $this->assertSame(0, WaitingTime::hoursSince(now()->addMinutes(12)), 'a queue cannot say a row arrived in the future');
    }

    public function test_the_document_queue_reports_a_non_negative_wait_for_waiting_papers(): void
    {
        [$staff] = $this->accountWithOrganization('SuperAdmin', 'admin', 'مراجع المنصة');
        $organization = $this->organization('seller', 'مطاحن الانتظار');

        $old = $this->filed($organization);
        $fresh = $this->filed($organization, OrganizationDocument::TYPE_ID_PROOF);
        $fresh->forceFill(['created_at' => now()])->save();

        Sanctum::actingAs($staff);

        $rows = $this->getJson('/api/v1/organizations/document-queue?per_page=50')
            ->assertOk()
            ->json('data');

        $byId = collect($rows)->keyBy('id');

        $this->assertSame(5, $byId[$old->id]['waiting_hours'], 'a paper filed five hours ago has waited five hours');
        $this->assertSame(0, $byId[$fresh->id]['waiting_hours']);

        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual(0, $row['waiting_hours']);
        }
    }

    public function test_the_offer_moderation_queue_reports_a_non_negative_wait(): void
    {
        [$staff] = $this->accountWithOrganization('SuperAdmin', 'admin', 'مراجع المنصة');
        $seller = $this->organization('seller', 'مصانع المراقبة');

        $offer = $this->offer($seller, ['status' => Offer::STATUS_PENDING_REVIEW]);
        $offer->forceFill(['updated_at' => now()->subHours(7)->subMinutes(20)])->save();

        Sanctum::actingAs($staff);

        $rows = $this->getJson('/api/v1/marketplace/moderation-queue?per_page=50')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $hours = $row['moderation']['waiting_hours'] ?? null;

            $this->assertNotNull($hours, 'every row in the queue must carry its wait');
            $this->assertGreaterThanOrEqual(0, $hours);
        }

        $this->assertSame(7, collect($rows)->firstWhere('id', $offer->id)['moderation']['waiting_hours']);
    }

    private function filed(Organization $organization, string $type = OrganizationDocument::TYPE_COMMERCIAL_REGISTRATION): OrganizationDocument
    {
        $document = OrganizationDocument::create([
            'organization_id'  => $organization->id,
            'type'             => $type,
            'reference_number' => 'REF-' . $organization->id . '-' . $type,
            'disk'             => 'local',
            'path'             => 'organizations/' . $organization->id . '/documents/' . $type . '.pdf',
            'original_name'    => $type . '.pdf',
            'mime_type'        => 'application/pdf',
            'size_bytes'       => 20480,
            'status'           => OrganizationDocument::STATUS_PENDING,
            'uploaded_by'      => $organization->members()->first()?->id,
        ]);

        $document->forceFill(['created_at' => now()->subHours(5)])->save();

        return $document;
    }
}
