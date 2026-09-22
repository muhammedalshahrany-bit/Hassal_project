<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * Whether an account's state and its roles are facts the system acts on.
 *
 * Two defects are pinned here, and both were reproduced against the running
 * deployment before being fixed rather than reasoned about:
 *
 *   Suspension decided nothing. `AuthController` refused login on a non-active
 *   account, but nothing checked the status after that — a token minted while
 *   the account was active kept working through the suspension, reaching
 *   authorization middleware and validation on write endpoints. The lockout was
 *   a button label.
 *
 *   "تغيير الدور" changed the wrong thing. The console offered SellerAdmin and
 *   BuyerAdmin under a platform heading, and assigning one wrote a global Spatie
 *   row while the person's standing inside their own company
 *   (`organization_memberships.role`) stayed exactly where it was.
 *
 * Tests assert behaviour through HTTP where the defect was reachable, and
 * through the database where the guarantee is the column's.
 */
class AccountGovernanceTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /** A SuperAdmin able to reach `/users`, plus the account under review. */
    private function console(): array
    {
        [$admin] = $this->accountWithOrganization('SuperAdmin', 'seller', 'إدارة المنصة');

        return [$admin, $this->user('حساب للمراجعة')];
    }

    public function test_a_token_minted_before_a_suspension_stops_working_after_it(): void
    {
        $user = $this->user();
        $token = $user->createToken('device')->plainTextToken;

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        $user->forceFill(['status' => 'suspended'])->save();

        // Each request in production boots a fresh container, so the guard
        // cannot still be holding the user object from the earlier one. The test
        // process keeps its container between calls, so the guards are dropped
        // here to ask the question the middleware actually answers: what does a
        // request made *after* the suspension see?
        $this->app['auth']->forgetGuards();

        // The refusal has to name the state it refused, or an operator chasing a
        // report of "it stopped working" has nothing to look at.
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ACCOUNT_NOT_ACTIVE')
            ->assertJsonPath('error.details.status', 'suspended');
    }

    public function test_a_suspended_request_still_carries_the_security_headers(): void
    {
        $user = $this->user();
        $token = $user->createToken('device')->plainTextToken;
        $user->forceFill(['status' => 'inactive'])->save();

        // `SecurityHeaders` sits outside the account check on purpose. A 403 is
        // still a response the browser renders, so it still must not be sniffed
        // or framed.
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertForbidden()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_an_inactive_account_cannot_reach_a_write_endpoint(): void
    {
        [$user] = $this->accountWithOrganization('SellerAdmin', 'seller', 'منشأة موقوفة');
        $token = $user->createToken('device')->plainTextToken;
        $user->forceFill(['status' => 'inactive'])->save();

        $this->postJson('/api/v1/warehouses', [], ['Authorization' => "Bearer {$token}"])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ACCOUNT_NOT_ACTIVE');
    }

    public function test_suspending_an_account_from_the_console_closes_its_sessions(): void
    {
        [$admin, $target] = $this->console();
        $target->createToken('laptop');
        $target->createToken('phone');

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/users/{$target->id}/status", [
            'status' => 'suspended',
            'reason' => 'بلاغات تجارية لم تُحل',
        ], ['X-Idempotency-Key' => 'st-' . $target->id])
            ->assertOk()
            ->assertJsonPath('meta.closed_sessions', 2);

        $this->assertSame(0, $target->tokens()->count());

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'USER_STATUS_UPDATE',
            'auditable_id' => $target->id,
        ]);
    }

    public function test_the_console_lists_only_platform_roles(): void
    {
        [$admin] = $this->console();
        Sanctum::actingAs($admin);

        /** @var list<string> $platform */
        $platform = config('rbac.platform_roles');

        $names = collect($this->getJson('/api/v1/users/roles')->assertOk()->json('data'))
            ->pluck('name')
            ->all();

        // Ordered by name on the way out; the config lists them in grant order.
        $this->assertEqualsCanonicalizing($platform, $names);
        $this->assertNotContains('SellerAdmin', $names);
        $this->assertNotContains('BuyerAdmin', $names);
    }

    public function test_a_business_role_can_no_longer_be_assigned_from_the_platform_console(): void
    {
        [$admin, $target] = $this->console();
        $target->assignRole('SellerAdmin');
        Sanctum::actingAs($admin);

        // The role exists in the `roles` table, so the old `exists:roles,name`
        // rule accepted it. The boundary is what a platform operator may grant.
        $this->postJson("/api/v1/users/{$target->id}/roles", [
            'role_name' => 'SellerAdmin',
            'reason' => 'تجربة',
        ], ['X-Idempotency-Key' => 'br-' . $target->id])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['role_name']]]);
    }

    public function test_granting_a_platform_role_requires_a_reason_and_records_it(): void
    {
        [$admin, $target] = $this->console();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/users/{$target->id}/roles", ['role_name' => 'Auditor'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['reason']]]);

        $this->postJson("/api/v1/users/{$target->id}/roles", [
            'role_name' => 'Auditor',
            'reason' => 'مراجعة مالية دورية',
        ], ['X-Idempotency-Key' => 'gr-' . $target->id])
            ->assertOk();

        $this->assertTrue($target->fresh()->hasRole('Auditor'));

        $audit = AuditLog::where('action', 'USER_ROLE_ASSIGNED')->latest('id')->first();
        $this->assertSame('مراجعة مالية دورية', $audit->new_values['reason']);
    }

    /**
     * The reason arrives in a DELETE body. If Laravel did not read it, every
     * revocation would 422 and the console could never remove a role — so this
     * asserts the wire format, not just the rule.
     */
    public function test_revoking_a_platform_role_needs_a_reason_and_refuses_self_removal(): void
    {
        $admin = $this->user('مشرف المنصة');
        $admin->assignRole('SuperAdmin');
        $other = $this->user('مشرف ثانٍ');
        $other->assignRole('SuperAdmin');
        $other->assignRole('Auditor');

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/users/{$other->id}/roles/Auditor")
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['reason']]]);

        $this->deleteJson("/api/v1/users/{$other->id}/roles/Auditor", [
            'reason' => 'انتهت فترة المراجعة',
        ])->assertOk();

        $this->assertFalse($other->fresh()->hasRole('Auditor'));

        $this->deleteJson("/api/v1/users/{$admin->id}/roles/SuperAdmin", [
            'reason' => 'تجربة على نفسي',
        ])->assertUnprocessable()->assertJsonPath('error_code', 'CANNOT_REVOKE_SELF');
    }

    /**
     * The rule bites when the account being cleared out is the last one holding
     * `SuperAdmin` — which a SuperAdmin can never be, since revoking somebody
     * else always leaves themselves behind. So the case is exercised through a
     * support desk that was given `users.manage` without the role: the same
     * request, the same guard, no way to argue it away.
     */
    public function test_the_last_super_admin_cannot_be_revoked(): void
    {
        $last = $this->user('المشرف الأخير');
        $last->assignRole('SuperAdmin');

        $desk = $this->user('مكتب الدعم');
        $desk->assignRole($this->roleWithUsersManage());

        Sanctum::actingAs($desk);

        $this->deleteJson("/api/v1/users/{$last->id}/roles/SuperAdmin", [
            'reason' => 'انتهت ولايته',
        ])->assertUnprocessable()->assertJsonPath('error_code', 'LAST_SUPER_ADMIN');

        $this->assertTrue($last->fresh()->hasRole('SuperAdmin'));
    }

    public function test_revoking_the_other_super_admin_leaves_the_platform_operable(): void
    {
        $actor = $this->user('مشرف أول');
        $actor->assignRole('SuperAdmin');
        $second = $this->user('مشرف ثانٍ');
        $second->assignRole('SuperAdmin');

        Sanctum::actingAs($actor);

        $this->deleteJson("/api/v1/users/{$second->id}/roles/SuperAdmin", [
            'reason' => 'تسليم المهام',
        ])->assertOk();

        $this->assertFalse($second->fresh()->hasRole('SuperAdmin'));
        $this->assertTrue($actor->fresh()->hasRole('SuperAdmin'));
    }

    /** A role that may reach `/users` without being a SuperAdmin. */
    private function roleWithUsersManage(): Role
    {
        $role = Role::findOrCreate('SupportDesk', 'web');
        $role->syncPermissions([Permission::findOrCreate('users.manage', 'web')]);

        return $role;
    }

    public function test_a_membership_change_is_made_with_a_reason_and_affects_only_that_company(): void
    {
        [$owner, $organization] = $this->accountWithOrganization('SellerAdmin', 'seller', 'شركة الحليب');
        $staff = $this->user('موظف التوزيع');
        $this->membership($organization, $staff, 'staff');

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/organizations/{$organization->id}/members/{$staff->id}", [
            'role' => 'finance',
        ], ['X-Idempotency-Key' => 'mr-' . $staff->id])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['reason']]]);

        // An idempotency key names one attempt, not one form: replaying the
        // rejected attempt with the same key returns the stored refusal, which is
        // what makes it safe for the client to retry a dropped request without
        // double-applying it. A corrected submission is a new attempt and gets a
        // new key — as `api.ts` generates one per call.
        $this->putJson("/api/v1/organizations/{$organization->id}/members/{$staff->id}", [
            'role' => 'finance',
        ], ['X-Idempotency-Key' => 'mr-' . $staff->id])
            ->assertUnprocessable()
            ->assertHeader('X-Idempotency-Replay', 'true');

        $this->putJson("/api/v1/organizations/{$organization->id}/members/{$staff->id}", [
            'role' => 'finance',
            'reason' => 'تسليم دفاتر الحسابات',
        ], ['X-Idempotency-Key' => 'mr2-' . $staff->id])
            ->assertOk()
            ->assertJsonPath('data.role', 'finance');

        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $staff->id,
            'role' => 'finance',
        ]);

        // The global Spatie row is untouched: business standing is per company.
        $this->assertFalse($staff->hasRole('SellerAdmin'));

        $audit = AuditLog::where('action', 'ORG_MEMBER_ROLE_CHANGED')->latest('id')->first();
        $this->assertSame(['role' => 'staff', 'is_active' => true], $audit->old_values);
        $this->assertSame('تسليم دفاتر الحسابات', $audit->new_values['reason']);
    }

    public function test_staff_is_a_valid_membership_role_and_operator_is_not(): void
    {
        [$owner, $organization] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مزرعة');
        $member = $this->user('موظف');
        $this->membership($organization, $member, 'staff');

        Sanctum::actingAs($owner);

        // `staff` is what the rows already held and what no validator accepted,
        // so re-saving any existing member used to fail with their own roster
        // described as invalid.
        $this->putJson("/api/v1/organizations/{$organization->id}/members/{$member->id}", [
            'role' => 'staff',
            'reason' => 'تثبيت الوضع الحالي',
        ], ['X-Idempotency-Key' => 'st-' . $member->id])
            ->assertOk();

        // The word the column default used, now retired by the migration that
        // mapped it onto `staff`.
        $this->putJson("/api/v1/organizations/{$organization->id}/members/{$member->id}", [
            'role' => 'operator',
            'reason' => 'تجربة مفردة قديمة',
        ], ['X-Idempotency-Key' => 'op-' . $member->id])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['role']]]);
    }

    public function test_an_admin_cannot_grant_ownership_and_the_last_owner_cannot_step_down_alone(): void
    {
        [$owner, $organization] = $this->accountWithOrganization('SellerAdmin', 'seller', 'شركة النقل');
        $admin = $this->user('مدير تنفيذي');
        $this->membership($organization, $admin, 'admin');
        $admin->assignRole('SellerAdmin');

        Sanctum::actingAs($admin);

        // Whoever can set `owner` can promote themselves to it.
        $this->putJson("/api/v1/organizations/{$organization->id}/members/{$admin->id}", [
            'role' => 'owner',
            'reason' => 'أريد الصلاحية الكاملة',
        ], ['X-Idempotency-Key' => 'ow-' . $admin->id])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'OWNERSHIP_MOVE_FORBIDDEN');

        $this->assertSame('admin', $admin->fresh()->roleInOrganization($organization->id));
    }

    public function test_the_last_active_owner_cannot_be_demoted(): void
    {
        [$owner, $organization] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مصنع');

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/organizations/{$organization->id}/members/{$owner->id}", [
            'role' => 'staff',
            'reason' => 'أرغب بالتنحي',
        ], ['X-Idempotency-Key' => 'lo-' . $owner->id])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'LAST_OWNER_REQUIRED');

        $this->assertSame('owner', $owner->fresh()->roleInOrganization($organization->id));
    }

    public function test_the_membership_vocabulary_is_served_rather_than_typed_out(): void
    {
        Sanctum::actingAs($this->user());

        $options = collect($this->getJson('/api/v1/organizations/membership-roles')->assertOk()->json('data'));

        $this->assertSame(array_keys(config('rbac.membership_roles')), $options->pluck('value')->all());

        // The vocabulary arrives with its meaning. An owner appointing a
        // colleague from this list is making a real decision, and a label alone
        // («المالية») does not say that the person chosen will not be able to
        // move stock — so the grants the server will honour are published
        // beside the name the screen shows.
        foreach ($options as $option) {
            $grants = OrganizationMembership::permissionsFor($option['value']);

            $this->assertSame($grants, $option['permissions']);
            $this->assertSame(count($grants), $option['permission_count']);
            $this->assertNotEmpty($option['label']);
        }

        // And the platform's own roles are listed the same way, because that tab
        // is exactly where a bare name once invited the wrong click. The route is
        // `users.manage`, so it takes a platform account to read it.
        $admin = $this->user('مشرف المنصة');
        $admin->assignRole('SuperAdmin');
        Sanctum::actingAs($admin);

        $platform = collect($this->getJson('/api/v1/users/roles')->assertOk()->json('data'));

        // Both sides sorted: the endpoint orders by name, the config lists the
        // owner first, and the order of either carries no meaning.
        $this->assertSame(
            collect(config('rbac.platform_roles'))->sort()->values()->all(),
            $platform->pluck('name')->sort()->values()->all()
        );

        foreach ($platform as $role) {
            $this->assertSame($role['permission_count'], count($role['permissions']));
            $this->assertNotEmpty($role['permissions'], "Platform role {$role['name']} reports no capabilities.");
        }

        $auditor = $platform->firstWhere('name', 'Auditor');
        $this->assertNotContains('users.manage', $auditor['permissions']);
        $this->assertNotContains('offers.create', $auditor['permissions'], 'A read-only platform role holds a business write.');
    }

    /**
     * The console's second column reads `organizations[].role`.
     *
     * While `/users` handed back Eloquent models untouched, that standing lived
     * only in `organizations[].pivot.role`. The screen found nothing at the field
     * its own type promised, fell back to the dropdown's first option, and showed
     * «مالك المنشأة» for a person the database recorded as `staff` — which is the
     * other half of the report that changing a role "does not reflect". The
     * listing also dumped whole organization rows, legal numbers included, into a
     * table that displays a name and a role.
     */
    public function test_the_accounts_listing_states_the_membership_role_where_the_console_reads_it(): void
    {
        [$admin] = $this->accountWithOrganization('SuperAdmin', 'seller', 'إدارة المنصة');

        $company = $this->organization('seller', 'مخبز البرح');
        $clerk = $this->user('أمين المستودع');
        $this->membership($company, $clerk, 'staff');
        $clerk->assignRole('SellerStaff');

        Sanctum::actingAs($admin);

        $row = collect($this->getJson('/api/v1/users?search=' . rawurlencode($clerk->name) . '&per_page=5')
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $clerk->id);

        $this->assertNotNull($row, 'The server filters by name, so the account must be findable.');

        $organization = collect($row['organizations'])->firstWhere('id', $company->id);
        $this->assertSame('staff', $organization['role']);
        $this->assertTrue($organization['membership_active']);
        $this->assertArrayNotHasKey('pivot', $organization);
        $this->assertArrayNotHasKey('commercial_registration_number', $organization);

        // Role names, not role records: the same shape `/auth/me` uses, so one
        // client type cannot be right for one endpoint and wrong for the other.
        $this->assertSame(['SellerStaff'], $row['roles']);
    }

    public function test_the_database_refuses_a_role_no_validator_would_accept(): void
    {
        [$owner, $organization] = $this->accountWithOrganization('SellerAdmin', 'seller', 'ورشة');

        $this->expectException(QueryException::class);

        OrganizationMembership::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'role' => 'co-founder',
            'is_active' => true,
            'joined_at' => now(),
        ]);
    }

    public function test_the_database_refuses_an_account_state_it_does_not_understand(): void
    {
        $this->expectException(QueryException::class);

        User::create([
            'name' => 'حساب بحالة مخترعة',
            'email' => 'invented-status@example.test',
            'password' => 'x',
            'status' => 'SUSPENDED',
        ]);
    }
}
