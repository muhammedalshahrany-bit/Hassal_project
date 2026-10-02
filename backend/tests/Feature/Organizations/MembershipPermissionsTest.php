<?php

namespace Tests\Feature\Organizations;

use App\Marketplace\Models\Offer;
use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationMembership;
use App\Identity\Models\User;
use App\Core\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * Where business authority actually comes from.
 *
 * §36 says a permission that only the front end honours is decoration, and the
 * corollary this file exists for: a permission attached to the wrong *subject*
 * is decoration too. `SellerAdmin` and `BuyerStaff` were Spatie roles, so they
 * granted a person authority over every company they had ever joined and kept
 * it after they left. That authority now lives in
 * `config('rbac.membership_permissions')` and is read from the membership row of
 * the organization a request is acting for, which is the decision the operator
 * made when the role model was redrawn.
 *
 * So these tests ask four things of the matrix: that it is well-formed (every
 * name exists, every standing is covered, ownership is the widest), that it
 * stops at the company boundary (nothing platform-wide is reachable from it),
 * that it is read per company rather than carried between them, and that the
 * route middleware and `/auth/me` answer from it — because a model method that
 * agrees with itself while `permission:offers.delete` disagrees is the bug this
 * whole layer was meant to remove.
 */
class MembershipPermissionsTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    /**
     * What a company may never be granted about anybody else.
     *
     * Moderating a listing, verifying a business, ruling a dispute between two
     * parties, staffing accounts, editing the platform's settings or its currency
     * definitions: each is an act on someone else's transaction, so a counterparty
     * holding it would be judging its own case.
     */
    private const PLATFORM_ONLY = [
        'offers.moderate',
        'community.moderate',
        'organizations.verify',
        'disputes.resolve',
        'users.manage',
        'settings.manage',
        'currencies.manage',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /** @return array<string, list<string>> */
    private function matrix(): array
    {
        return (array) config('rbac.membership_permissions');
    }

    public function test_the_matrix_covers_every_standing_and_names_only_real_permissions(): void
    {
        $declared = (array) config('rbac.permissions');

        $this->assertSame(
            OrganizationMembership::roleNames(),
            array_keys($this->matrix()),
            'A membership standing exists with no grant list, or a grant list exists for a standing the vocabulary does not have.'
        );

        foreach ($this->matrix() as $standing => $grants) {
            $this->assertSame(
                $grants,
                array_values(array_unique($grants)),
                "The standing '{$standing}' lists a permission twice."
            );

            $this->assertSame(
                [],
                array_values(array_diff($grants, $declared)),
                "The standing '{$standing}' grants names the catalog never declared."
            );
        }

        // The model reads the same config the HTTP layer ends up reading, so the
        // two cannot drift into describing different systems.
        foreach (OrganizationMembership::roleNames() as $standing) {
            $this->assertSame(
                $this->matrix()[$standing],
                OrganizationMembership::permissionsFor($standing),
                "OrganizationMembership::permissionsFor('{$standing}') does not match the matrix."
            );
        }

        // A standing outside the vocabulary grants nothing rather than everything.
        $this->assertSame([], OrganizationMembership::permissionsFor('administrator'));
    }

    public function test_no_standing_in_a_company_reaches_a_capability_that_belongs_to_the_platform(): void
    {
        $business = OrganizationMembership::businessPermissions();

        foreach (self::PLATFORM_ONLY as $name) {
            $this->assertContains($name, (array) config('rbac.permissions'), "{$name} is enforced but not declared.");
            $this->assertNotContains($name, $business, "A company standing holds {$name}, which decides other people's transactions.");
            $this->assertFalse(OrganizationMembership::isBusinessPermission($name));
        }

        // Reading the platform's every-company books is not the same act as
        // reading your own, and the split is by caller rather than by name:
        // `ledger.view` is business-held because the ledger routes read the
        // organization in context, while the platform-wide walk lives behind a
        // SuperAdmin check in the controller.
        $this->assertContains('ledger.view', $business);
    }

    public function test_ownership_is_the_widest_standing_inside_the_company(): void
    {
        $owner = $this->matrix()['owner'];

        foreach ($this->matrix() as $standing => $grants) {
            $missing = array_values(array_diff($grants, $owner));
            $this->assertSame(
                [],
                $missing,
                "'{$standing}' holds capabilities ownership does not: " . implode(', ', $missing)
            );
        }

        // Staffing a company belongs to owner and admin, and to nobody else —
        // `OrganizationMembership::canManage()` is derived from the matrix rather
        // than maintained beside it, which is why it moves when the matrix does.
        foreach (['owner', 'admin'] as $standing) {
            $this->assertTrue(
                OrganizationMembership::canManage($standing),
                "{$standing} cannot manage members even though it is meant to."
            );
        }

        foreach (['staff', 'finance', 'auditor'] as $standing) {
            $this->assertFalse(OrganizationMembership::canManage($standing));
        }
    }

    public function test_a_standing_is_read_per_company_and_does_not_follow_the_person(): void
    {
        [$ada, $first] = $this->accountWithOrganization(null, 'seller', 'شركة ألف');
        $second = $this->organization('buyer', 'شركة باء');

        // The same account runs one company and works in the next.
        $this->membership($second, $ada, 'staff');

        $this->inOrganization($ada, $first, function () use ($ada) {
            $this->assertTrue($ada->can('offers.delete'), 'An owner cannot withdraw a listing from their own company.');
            $this->assertTrue($ada->can('members.manage'));
            $this->assertTrue($ada->can('api.manage'));
        });

        $this->inOrganization($ada, $second, function () use ($ada) {
            $this->assertFalse($ada->can('offers.delete'), 'Working in a company as staff made them an owner of it.');
            $this->assertFalse($ada->can('members.manage'), 'A staff member can change who else works there.');
            $this->assertFalse($ada->can('api.manage'), 'A staff member can issue machine credentials.');
            $this->assertTrue($ada->can('offers.create'), 'A staff member cannot do the job they were hired for.');
            $this->assertTrue($ada->can('warehouses.manage'));
        });

        // And with no organization resolved, neither standing is readable.
        TenantContext::clear();
        $this->assertFalse($ada->can('offers.create'));
    }

    public function test_the_access_a_company_granted_is_the_access_ending_the_membership_takes_back(): void
    {
        $organization = $this->organization('seller', 'مصنع');
        $member = $this->memberOf($organization, 'admin');

        $this->inOrganization($member, $organization, function () use ($member) {
            $this->assertTrue($member->can('offers.delete'));
        });

        // Revoking access is one column on the membership row. It used to need
        // somebody to remember which global roles to uncheck, which is the kind
        // of rule that holds until the one account nobody revisited.
        $organization->memberships()->where('user_id', $member->id)->firstOrFail()
            ->forceFill(['is_active' => false])
            ->save();

        $this->inOrganization($member, $organization, function () use ($member) {
            $this->assertFalse($member->can('offers.delete'));
            $this->assertSame([], $member->currentOrganizationPermissions());
        });

        // A context somebody else resolved grants nothing to this account: the
        // membership in it belongs to a different person.
        $outsider = $this->user('حساب لا علاقة له');
        $this->inOrganization($outsider, $organization, function () use ($outsider) {
            $this->assertFalse($outsider->can('offers.view'));
            $this->assertSame([], $outsider->currentOrganizationPermissions());
        });
    }

    public function test_the_retired_business_roles_grant_nothing_and_change_nothing(): void
    {
        $account = $this->user('حساب من زمن الأدوار العامة');
        $account->assignRole('SellerAdmin');
        $account->assignRole('BuyerStaff');

        // The rows still resolve — an old assignment must not throw — and they
        // hold exactly what their empty grant lists say. The unaffiliated
        // account does hold the declared person capabilities — liking, asking —
        // and everything beyond that closed list must come from a company or a
        // platform role, never from these retired rows.
        $this->assertSame(
            ['BuyerStaff', 'SellerAdmin'],
            $account->fresh()->getRoleNames()->sort()->values()->all()
        );
        $this->assertSame(
            [],
            array_values(array_diff(
                $account->fresh()->getAllPermissions()->pluck('name')->all(),
                \App\Core\Rbac\RbacScope::personCapabilities()
            )),
            'A retired business role leaked a capability beyond the person standing.'
        );
        $this->assertFalse($account->can('offers.create'));

        // And attaching one to a real colleague does not quietly widen the standing.
        $organization = $this->organization('seller', 'ورشة');
        $staff = $this->memberOf($organization, 'staff');
        $staff->assignRole('SellerAdmin');

        $this->inOrganization($staff, $organization, function () use ($staff) {
            $this->assertFalse($staff->can('offers.delete'));
            $this->assertSame(
                OrganizationMembership::permissionsFor('staff'),
                $staff->currentOrganizationPermissions()
            );
        });
    }

    public function test_the_route_layer_answers_from_the_company_standing(): void
    {
        $organization = $this->organization('seller', 'مخزن');
        $draft = $this->offer($organization, [
            'status' => Offer::STATUS_DRAFT,
            'published_at' => null,
        ]);

        $staff = $this->memberOf($organization, 'staff');
        $auditor = $this->memberOf($organization, 'auditor');
        $owner = $this->memberOf($organization, 'owner');

        // Three people, one company, three different answers from the same route.
        // The refusal is the middleware's, not a hidden button's.
        Sanctum::actingAs($staff);

        $this->deleteJson('/api/v1/marketplace/offers/' . $draft->id, [], $this->withTenant($organization))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PERMISSION_DENIED')
            ->assertJsonPath('error.details.required_permission', 'offers.delete');

        $this->assertDatabaseHas('offers', ['id' => $draft->id]);

        // Staff is granted the create route, so it reaches validation and is
        // refused there instead — the pair of codes is the evidence that the
        // standing, not the company, decided.
        $this->postJson('/api/v1/marketplace/offers', [], $this->withTenant($organization))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');

        $this->getJson('/api/v1/notifications', $this->withTenant($organization))->assertOk();

        Sanctum::actingAs($auditor);

        // The auditor is inside the company and still cannot write anything: the
        // read grants are the whole of the standing.
        $this->postJson('/api/v1/marketplace/offers', [], $this->withTenant($organization))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PERMISSION_DENIED')
            ->assertJsonPath('error.details.required_permission', 'offers.create');

        $this->deleteJson('/api/v1/marketplace/offers/' . $draft->id, [], $this->withTenant($organization))
            ->assertStatus(403)
            ->assertJsonPath('error.details.required_permission', 'offers.delete');

        $this->getJson('/api/v1/notifications', $this->withTenant($organization))->assertOk();
        $this->getJson('/api/v1/marketplace/my-offers', $this->withTenant($organization))->assertOk();

        Sanctum::actingAs($owner);

        $this->deleteJson('/api/v1/marketplace/offers/' . $draft->id, [], $this->withTenant($organization))
            ->assertOk();

        // An offer is withdrawn, not erased: it disappears from the shelf and
        // stays in the table, because a deal may already name it.
        $this->assertSoftDeleted('offers', ['id' => $draft->id]);
    }

    public function test_the_profile_endpoint_reports_the_effective_set_and_no_more(): void
    {
        $organization = $this->organization('seller', 'شركة');
        $member = $this->memberOf($organization, 'finance');

        Sanctum::actingAs($member);

        $reported = $this->effectivePermissions($organization);
        $expected = collect(OrganizationMembership::permissionsFor('finance'))->sort()->values();

        $this->assertSame($expected->all(), $reported->all());

        // The money standing sees its own reports and not the platform's.
        $this->assertContains('reports.view', $reported->all());
        $this->assertContains('ledger.view', $reported->all());
        $this->assertNotContains('offers.moderate', $reported->all());
        $this->assertNotContains('users.manage', $reported->all());
        $this->assertNotContains('offers.create', $reported->all());

        // A platform role adds to the answer without replacing it: what the
        // account is everywhere, plus what this person is in this company.
        $observer = $this->memberOf($organization, 'staff');
        $observer->assignRole('Auditor');

        Sanctum::actingAs($observer);

        $both = $this->effectivePermissions($organization);

        $this->assertContains('audit.view', $both->all());
        $this->assertContains('offers.create', $both->all());
        $this->assertNotContains('users.manage', $both->all());
    }

    /**
     * What `/auth/me` says this account may do, acting for one company.
     *
     * Sorted, because the set is the assertion and the order it arrives in is
     * not: a client that rendered its buttons from an ordered list would be
     * depending on a coincidence.
     */
    private function effectivePermissions(Organization $organization): \Illuminate\Support\Collection
    {
        $permissions = $this->getJson('/api/v1/auth/me', $this->withTenant($organization))
            ->assertOk()
            ->json('data.user.permissions');

        return collect($permissions)->values()->sort()->values();
    }

    /**
     * Ask the question as the account, inside one company.
     *
     * The helper restores the previous context either way, because the static it
     * writes survives the request that set it — which is exactly the assumption a
     * test must not share with production.
     */
    private function inOrganization(User $user, Organization $organization, callable $assertions): void
    {
        $membership = $organization->memberships()->where('user_id', $user->id)->first();

        $previousOrganization = TenantContext::get();
        $previousMembership = TenantContext::membership();

        TenantContext::set($organization, $membership);

        try {
            $assertions();
        } finally {
            if ($previousOrganization) {
                TenantContext::set($previousOrganization, $previousMembership);
            } else {
                TenantContext::clear();
            }
        }
    }
}
