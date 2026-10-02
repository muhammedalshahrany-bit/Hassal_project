<?php

namespace Tests\Feature\Identity;

use App\Marketplace\Models\Offer;
use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationMemberPermission;
use App\Organizations\Models\OrganizationMembership;
use App\Organizations\Models\OrganizationRole;
use App\Identity\Models\User;
use App\Core\Tenant\TenantContext;
use App\Core\Rbac\RbacScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * The two doors, and the wall between them.
 *
 * `config/rbac.php` now classifies every capability by the *subject* that may hold
 * it, and this file is the executable form of that classification. It asks four
 * questions, all of them about the boundary rather than about any individual
 * grant:
 *
 *   * Can the company door attach a platform capability to a company standing? It
 *     must not, at the write and — separately — on the read, because a row can also
 *     arrive by migration or by a database console.
 *   * Can the platform door attach a company capability to an account? It must not,
 *     for the reason the retired SellerAdmin roles taught: a grant on the person
 *     follows them into businesses they have no relation to and survives their
 *     leaving.
 *   * Does authority stay inside the company that granted it — across two companies,
 *     and across a standing the company invented for itself?
 *   * Do the individual overrides actually override, in both directions, and does
 *     the one person who must not be locked out stay able to staff the company?
 *
 * A refusal here is asserted as a route response rather than as a model method
 * returning false: the middleware is the door an operator uses, and a rule that
 * lives only in a helper is a rule a screen can walk past.
 */
class PermissionIsolationTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    /** A capability the platform kept for itself, by name. */
    private const PLATFORM_NAME = 'offers.moderate';

    /** A capability no company standing may hold, by name. */
    private const BUSINESS_NAME = 'offers.create';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    public function test_the_catalog_classifies_every_name_exactly_once(): void
    {
        $declared = (array) config('rbac.permissions');
        $scopes = (array) config('rbac.permission_scopes');

        $classified = [...($scopes['platform'] ?? []), ...($scopes['both'] ?? []), ...($scopes['business'] ?? [])];

        // The seeder refuses to run otherwise; asserting it here means a rename in
        // the config is caught by the suite and not only by whoever next seeds.
        $this->assertSame([], array_values(array_diff($declared, $classified)), 'A permission carries no scope.');
        $this->assertCount(count($declared), array_unique($classified), 'A permission carries two scopes.');
        $this->assertSame([], array_values(array_diff($classified, $declared)), 'A scope names a permission the catalog never declared.');

        $this->assertSame(RbacScope::PLATFORM, RbacScope::scopeOf(self::PLATFORM_NAME));
        $this->assertSame(RbacScope::BUSINESS, RbacScope::scopeOf(self::BUSINESS_NAME));
    }

    public function test_the_company_door_refuses_a_platform_capability_by_name(): void
    {
        $organization = $this->organization('seller', 'مصنع ألف');
        $owner = $this->memberOf($organization, 'owner');

        Sanctum::actingAs($owner);

        // Provisioned by reading, as the editor screen does.
        $this->getJson('/api/v1/organizations/' . $organization->id . '/roles', $this->withTenant($organization))
            ->assertOk()
            ->assertJsonPath('data.roles.0.key', 'owner');

        $this->putJson('/api/v1/organizations/' . $organization->id . '/roles/staff', [
            'permissions' => [self::BUSINESS_NAME, self::PLATFORM_NAME],
            'reason' => 'تجربة عزل الصلاحيات',
        ], $this->withTenant($organization))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'ROLE_PERMISSION_REFUSED')
            ->assertJsonFragment(['permissions' => [self::PLATFORM_NAME]]);

        // The refused write wrote nothing: the standing is still what it was.
        $this->assertNotContains(
            self::PLATFORM_NAME,
            OrganizationRole::lookup($organization->id, 'staff')->permissionNames()
        );

        // And the list the door offers never contained it, so the refusal is the
        // same rule the screen applies rather than a surprise at submit time.
        $grantable = $this->getJson('/api/v1/organizations/' . $organization->id . '/roles', $this->withTenant($organization))
            ->assertOk()
            ->json('data.grantable_permissions');

        $this->assertNotContains(self::PLATFORM_NAME, array_column($grantable, 'name'));
        $this->assertContains(self::BUSINESS_NAME, array_column($grantable, 'name'));
    }

    public function test_a_platform_capability_rows_cannot_reach_a_company_route(): void
    {
        // The write door refuses; this is the other half — a row that arrived some
        // other way (a migration, a seed, a database console) must still grant
        // nothing, because the boundary is a read rule and not only a form rule.
        $organization = $this->organization('seller', 'مصنع باء');
        $staff = $this->memberOf($organization, 'staff');

        $roles = OrganizationRole::provisionOrganization($organization->id);

        $staffRole = null;

        foreach ($roles as $role) {
            if ($role->key === 'staff') {
                $staffRole = $role;
            }
        }

        $this->assertNotNull($staffRole, 'Provisioning did not create the platform standings.');

        DB::table('organization_role_permissions')->insert([
            'organization_role_id' => $staffRole->id,
            'permission_id' => DB::table('permissions')->where('name', self::PLATFORM_NAME)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotContains(
            self::PLATFORM_NAME,
            $staffRole->fresh('permissions')->permissionNames(),
            'The read path accepted a platform name on a company standing.'
        );

        $this->inOrganization($staff, $organization, function () use ($staff) {
            $this->assertFalse($staff->can(self::PLATFORM_NAME));
        });

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/marketplace/moderation-queue', $this->withTenant($organization))
            ->assertStatus(403)
            ->assertJsonPath('error.details.required_permission', self::PLATFORM_NAME);
    }

    public function test_the_platform_door_refuses_a_company_capability_and_a_company_role(): void
    {
        $admin = $this->user('إدارة المنصة');
        $admin->assignRole('SuperAdmin');

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/rbac/platform')->assertOk()
            ->assertJsonPath('data.roles.0.name', 'Auditor');

        $this->putJson('/api/v1/rbac/platform/users/permissions', [
            'user_ids' => [$this->user('محاسب')->id],
            'permissions' => [self::BUSINESS_NAME],
            'mode' => 'replace',
            'reason' => 'تجربة عزل الصلاحيات',
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'ACCOUNT_PERMISSION_REFUSED');

        // The company standings are edited by the company, from its own screen.
        $this->putJson('/api/v1/rbac/platform/roles/SellerAdmin/permissions', [
            'permissions' => ['deals.view'],
            'reason' => 'تجربة عزل الصلاحيات',
        ])->assertStatus(422)->assertJsonPath('error_code', 'ROLE_NOT_PLATFORM');

        // The platform owner is declared in the file, not edited from the console.
        $this->putJson('/api/v1/rbac/platform/roles/SuperAdmin/permissions', [
            'permissions' => ['deals.view'],
            'reason' => 'تجربة عزل الصلاحيات',
        ])->assertStatus(422)->assertJsonPath('error_code', 'ROLE_IMMUTABLE');
    }

    public function test_a_business_capability_written_on_the_account_grants_nothing(): void
    {
        $organization = $this->organization('seller', 'مصنع جيم');
        $user = $this->memberOf($organization, 'auditor');

        // Not through any API: `givePermissionTo` is what an importer, a seeder or
        // a database console would do, and the row it writes is real.
        $user->givePermissionTo([self::BUSINESS_NAME]);

        // Spatie stores direct grants polymorphically; reading them off the model
        // keeps the assertion about the row rather than about its column names.
        $this->assertCount(
            1,
            $user->fresh()->permissions,
            'The fixture did not write the row this test is about.'
        );

        $this->assertSame(
            self::BUSINESS_NAME,
            $user->fresh()->permissions->firstWhere('name', self::BUSINESS_NAME)?->name,
            'The direct grant was written as something other than a company capability.'
        );

        $this->assertFalse(
            $user->fresh()->hasPermissionTo(self::BUSINESS_NAME),
            'A company capability on the account answered for the account.'
        );

        $this->assertNotContains(
            self::BUSINESS_NAME,
            $user->fresh()->getAllPermissions()->pluck('name')->all(),
            '`/auth/me` offered a door the enforcement path refuses.'
        );

        // Inside the company the same account is still only an auditor.
        $this->inOrganization($user, $organization, function () use ($user) {
            $this->assertFalse($user->can(self::BUSINESS_NAME));
        });
    }

    public function test_a_standing_one_company_invented_is_worth_only_what_that_company_said(): void
    {
        $first = $this->organization('seller', 'شركة ألف');
        $second = $this->organization('seller', 'شركة باء');

        $owner = $this->memberOf($first, 'owner');
        $this->membership($second, $owner, 'staff');

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/organizations/' . $first->id . '/roles', [
            'key' => 'warehouse_keeper',
            'name_ar' => 'أمين مستودع',
            'permissions' => ['warehouses.view', 'warehouses.manage', 'deals.deliver'],
            'reason' => 'تعيين الاستلام والتسليم لأمين المستودع',
        ], $this->withTenant($first))
            ->assertCreated()
            ->assertJsonPath('data.key', 'warehouse_keeper');

        // The company's own vocabulary is what its member screen offers.
        $offered = $this->getJson('/api/v1/organizations/membership-roles', $this->withTenant($first))
            ->assertOk()
            ->json('data');

        $this->assertContains('warehouse_keeper', array_column($offered, 'value'));
        $this->assertNotContains('warehouse_keeper', array_column(
            $this->getJson('/api/v1/organizations/membership-roles', $this->withTenant($second))->assertOk()->json('data'),
            'value'
        ));

        // The owner of ألف may staff ألف; the same account is staff at باء and has
        // no authority to create a standing there.
        $this->postJson('/api/v1/organizations/' . $second->id . '/roles', [
            'key' => 'warehouse_keeper',
            'name_ar' => 'أمين مستودع',
            'permissions' => ['warehouses.manage'],
            'reason' => 'محاولة إنشاء دور في شركة لا يديرها',
        ], $this->withTenant($second))->assertStatus(403);

        $member = $this->user('أمين المستودع');
        $this->membership($first, $member, 'staff');

        $this->putJson('/api/v1/organizations/' . $first->id . '/members/' . $member->id, [
            'role' => 'warehouse_keeper',
            'reason' => 'نقل أمين المستودع إلى دوره الجديد',
        ], $this->withTenant($first))
            ->assertOk()
            ->assertJsonPath('data.role', 'warehouse_keeper');

        $this->inOrganization($member, $first, function () use ($member) {
            $this->assertTrue($member->can('warehouses.manage'), 'The custom standing did not carry its grants.');
            $this->assertTrue($member->can('deals.deliver'));
            $this->assertFalse($member->can('offers.delete'), 'The custom standing carried something it was never given.');
            $this->assertFalse($member->can('members.manage'));
        });

        // The same account, in the company where it is only staff: nothing moved.
        $this->inOrganization($member, $second, function () use ($member) {
            $this->assertFalse($member->can('warehouses.manage'), 'A standing at one company followed the person to another.');
        });

        // A standing somebody holds cannot be retired from under them.
        $this->deleteJson('/api/v1/organizations/' . $first->id . '/roles/warehouse_keeper', [
            'reason' => 'محاولة حذف دور قيد الاستخدام',
        ], $this->withTenant($first))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'ROLE_IN_USE');
    }

    public function test_an_individual_grant_adds_to_a_standing_and_an_individual_denial_takes_away(): void
    {
        $organization = $this->organization('seller', 'مصنع دال');
        $owner = $this->memberOf($organization, 'owner');
        $staff = $this->memberOf($organization, 'staff');

        $draft = $this->offer($organization, [
            'status' => Offer::STATUS_DRAFT,
            'published_at' => null,
        ]);

        Sanctum::actingAs($owner);

        $this->inOrganization($staff, $organization, function () use ($staff) {
            $this->assertFalse($staff->can('offers.delete'), 'The fixture standing already holds what is being granted.');
        });

        $this->putJson('/api/v1/organizations/' . $organization->id . '/members/' . $staff->id . '/permissions', [
            'grants' => ['offers.delete'],
            'denials' => [],
            'reason' => 'تكليف بإغلاق المنشورات المنتهية',
        ], $this->withTenant($organization))
            ->assertOk()
            ->assertJsonFragment(['grants' => ['offers.delete']]);

        // The override is the member's own row, and the read door shows it beside
        // the standing it modifies — the screen has to be able to tell "staff, plus
        // this" from "a standing that says this".
        $this->getJson('/api/v1/organizations/' . $organization->id . '/members/' . $staff->id . '/permissions', $this->withTenant($organization))
            ->assertOk()
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonPath('data.grants', ['offers.delete']);

        $this->inOrganization($staff, $organization, function () use ($staff) {
            $this->assertTrue($staff->can('offers.delete'), 'An individual grant did not reach the decision.');
        });

        // The grant reached the route, not only the model: this is the staff
        // account deleting, and a minute ago the same request was a 403.
        Sanctum::actingAs($staff);

        $this->deleteJson('/api/v1/marketplace/offers/' . $draft->id, [], $this->withTenant($organization))
            ->assertOk();

        Sanctum::actingAs($owner);

        // Now take it back, and take the one capability the standing itself carried.
        $this->offer($organization, ['status' => Offer::STATUS_DRAFT, 'published_at' => null]);

        $this->putJson('/api/v1/organizations/' . $organization->id . '/members/' . $staff->id . '/permissions', [
            'grants' => [],
            'denials' => ['offers.create'],
            'reason' => 'إيقاف النشر مؤقتًا لهذا الموظف',
        ], $this->withTenant($organization))
            ->assertOk()
            ->assertJsonFragment(['denials' => ['offers.create']]);

        $this->inOrganization($staff, $organization, function () use ($staff) {
            $this->assertFalse($staff->can('offers.create'), 'A denial did not override the standing.');

            // The denial is about this person, not about the class: the standing
            // they hold still carries the capability for everybody else in it.
            $this->assertContains(
                'offers.create',
                OrganizationMembership::permissionsFor('staff'),
                'The individual denial was written as a change to the standing.'
            );
        });

        Sanctum::actingAs($staff);

        $this->postJson('/api/v1/marketplace/offers', [], $this->withTenant($organization))
            ->assertStatus(403)
            ->assertJsonPath('error.details.required_permission', 'offers.create');

        // A request that both grants and denies one name is refused outright,
        // rather than resolved by whichever precedence the server happens to apply.
        Sanctum::actingAs($owner);

        $this->putJson('/api/v1/organizations/' . $organization->id . '/members/' . $staff->id . '/permissions', [
            'grants' => ['offers.delete'],
            'denials' => ['offers.delete'],
            'reason' => 'تضارب في الطلب',
        ], $this->withTenant($organization))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'MEMBER_PERMISSION_CONTRADICTION');
    }

    public function test_bulk_overrides_apply_to_every_named_member_or_to_none(): void
    {
        $organization = $this->organization('seller', 'مصنع هاء');
        $owner = $this->memberOf($organization, 'owner');
        $first = $this->memberOf($organization, 'staff');
        $second = $this->memberOf($organization, 'staff');
        $outsider = $this->user('حساب خارج الشركة');

        Sanctum::actingAs($owner);

        $this->putJson('/api/v1/organizations/' . $organization->id . '/members/permissions/bulk', [
            'user_ids' => [$first->id, $second->id, $outsider->id],
            'grants' => ['reports.view'],
            'denials' => [],
            'reason' => 'تكليف التقارير لفريق المبيعات',
        ], $this->withTenant($organization))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'MEMBERSHIP_NOT_FOUND');

        $this->assertSame(
            0,
            OrganizationMemberPermission::query()->count(),
            'A rejected bulk call wrote the members it did recognise.'
        );

        $this->putJson('/api/v1/organizations/' . $organization->id . '/members/permissions/bulk', [
            'user_ids' => [$first->id, $second->id],
            'grants' => ['reports.view'],
            'denials' => ['offers.update'],
            'reason' => 'تكليف التقارير ومنع تعديل المنشورات',
        ], $this->withTenant($organization))->assertOk();

        foreach ([$first, $second] as $member) {
            $this->inOrganization($member, $organization, function () use ($member) {
                $this->assertTrue($member->can('reports.view'), 'The bulk grant skipped a named member.');
                $this->assertFalse($member->can('offers.update'), 'The bulk denial skipped a named member.');
            });
        }

        // A platform name cannot enter the company door in bulk any more than singly.
        $this->putJson('/api/v1/organizations/' . $organization->id . '/members/permissions/bulk', [
            'user_ids' => [$first->id],
            'grants' => [],
            'denials' => [self::PLATFORM_NAME],
            'reason' => 'محاولة سحب صلاحية المنصة من داخل الشركة',
        ], $this->withTenant($organization))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'MEMBER_PERMISSION_REFUSED');
    }

    public function test_the_owner_standing_cannot_be_edited_into_helplessness(): void
    {
        $organization = $this->organization('seller', 'مصنع واو');
        $owner = $this->memberOf($organization, 'owner');

        Sanctum::actingAs($owner);

        // Reading the company's matrix provisions the standings, as every editor
        // screen does before it offers a row to change.
        $this->getJson('/api/v1/organizations/' . $organization->id . '/roles', $this->withTenant($organization))
            ->assertOk();

        $this->putJson('/api/v1/organizations/' . $organization->id . '/roles/owner', [
            'permissions' => ['offers.view'],
            'reason' => 'تجريده من إدارة الأعضاء',
        ], $this->withTenant($organization))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OWNER_STANDING_PROTECTED');

        $this->putJson('/api/v1/organizations/' . $organization->id . '/members/' . $owner->id . '/permissions', [
            'grants' => [],
            'denials' => ['members.manage'],
            'reason' => 'منع المالك من التعيين',
        ], $this->withTenant($organization))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OWNER_STANDING_PROTECTED');

        $this->assertSame(
            0,
            OrganizationMemberPermission::query()->count(),
            'The refused denial was written before it was rejected.'
        );

        // A company whose owner cannot staff is recoverable only by the platform —
        // and that is the door that must stay open.
        $this->putJson('/api/v1/organizations/' . $organization->id . '/roles/owner', [
            'permissions' => ['offers.view', 'members.manage', 'organizations.manage'],
            'reason' => 'تضييق دور المالك مع إبقاء إدارة الأعضاء',
        ], $this->withTenant($organization))->assertOk();
    }

    public function test_a_company_without_staffing_authority_cannot_open_either_door(): void
    {
        $organization = $this->organization('seller', 'مصنع ياء');
        $staff = $this->memberOf($organization, 'staff');

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/organizations/' . $organization->id . '/roles', $this->withTenant($organization))
            ->assertStatus(403);

        $this->getJson('/api/v1/rbac/platform')->assertStatus(403);
    }

    /**
     * Run the assertions with the account acting inside one company.
     *
     * Deliberately a context set by hand rather than a request: these assertions are
     * about the resolution rule, and putting them behind a route would test the
     * middleware and hide the model.
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
