<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * What an account owes before it may found a business, and what the platform
 * says about it meanwhile.
 *
 * Registration (`POST /auth/register`) deliberately opens a *person*, not a
 * company — a typed-in company name used to become a business record with none
 * of the checks `POST /organizations` performs. So the completion question has
 * two halves, and they have to agree:
 *
 *   * the report — `/auth/me` and the sign-in response carry `profile_completion`
 *     so a screen can ask for exactly the missing fields;
 *   * the gate — `POST /organizations` refuses an account that has not supplied
 *     them, and refuses one that has already founded as many businesses as
 *     `account.max_organizations_per_account` allows.
 *
 * Both halves read the same `AccountRequirements`, which is the point of these
 * tests: a form that asks for what the server has stopped demanding, or a server
 * that refuses for something the form never showed, is the same bug in
 * opposite directions.
 *
 * Nothing here asserts that a phone number or an ID was *verified*. These are
 * claims a person typed; the platform requires them and holds them, and says so.
 */
class ProfileCompletionTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /**
     * An account with nothing but an address: no phone, no local password.
     *
     * Built directly rather than through `user()`, because that fixture is
     * complete on purpose — most scenarios are not about the missing field, and
     * a fixture that tripped the gate would make every other test dodge it.
     */
    private function bareAccount(): User
    {
        return User::create([
            'name' => 'حساب ناقص ' . Str::random(5),
            'email' => 'bare+' . Str::random(10) . '@example.test',
            'status' => 'active',
        ]);
    }

    /**
     * The payload a buyer would send with the legal identifiers switched off.
     *
     * @return array<string, string>
     */
    private function organizationPayload(string $label = 'منشأة'): array
    {
        $suffix = Str::upper(Str::random(8));

        return [
            'name_ar' => $label . ' ' . $suffix,
            'name_en' => $label . ' ' . $suffix,
            'type' => 'buyer',
            'commercial_registration_number' => 'CR-' . $suffix,
            'vat_number' => 'VAT-' . $suffix,
        ];
    }

    public function test_an_account_without_a_demanded_phone_cannot_found_a_business(): void
    {
        $user = $this->bareAccount();
        $this->setSetting('account.require_phone_number', '1', 'boolean');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/organizations', $this->organizationPayload());

        $response->assertForbidden()
            ->assertJsonPath('error_code', 'ACCOUNT_PROFILE_INCOMPLETE')
            ->assertJsonPath('error.details.missing_fields', ['phone']);

        // The refusal names the fields in the message too: a person reading an
        // error toast, not a JSON inspector, has to learn what to go and do.
        $this->assertStringContainsString('رقم الهاتف', $response->json('error.message'));

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_supplying_the_missing_field_through_the_profile_opens_the_door(): void
    {
        $user = $this->bareAccount();
        $this->setSetting('account.require_phone_number', '1', 'boolean');

        Sanctum::actingAs($user);

        $phone = '+9677' . Str::password(8, false, false);

        $this->patchJson('/api/v1/auth/profile', ['phone' => $phone])
            ->assertOk()
            ->assertJsonPath('data.profile_completion.missing_fields', []);

        $this->postJson('/api/v1/organizations', $this->organizationPayload())
            ->assertCreated();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'phone' => $phone]);
    }

    /**
     * The profile endpoint deliberately does *not* enforce the requirement while
     * the account is incomplete. Enforcing it there would lock the door a person
     * uses to open the other one.
     */
    public function test_an_incomplete_account_can_still_edit_its_profile(): void
    {
        $user = $this->bareAccount();
        $this->setSetting('account.require_phone_number', '1', 'boolean');

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/profile', ['name' => 'اسم مصحح'])
            ->assertOk()
            // ...and the response keeps saying what is outstanding, so the
            // screen does not conclude the account is finished.
            ->assertJsonPath('data.profile_completion.required', true)
            ->assertJsonPath('data.profile_completion.missing_fields', ['phone']);

        $this->assertSame('اسم مصحح', $user->fresh()->name);
    }

    public function test_the_required_field_list_follows_the_settings(): void
    {
        $user = $this->bareAccount();

        $this->setSetting('account.require_phone_number', '0', 'boolean');
        $this->setSetting('account.require_national_id', '0', 'boolean');

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.profile_completion.missing_fields', []);

        $this->setSetting('account.require_phone_number', '1', 'boolean');
        $this->setSetting('account.require_national_id', '1', 'boolean');

        $response = $this->getJson('/api/v1/auth/me')->assertOk();

        // Order is the order a form should show them in, and it comes from one
        // place — the same list `POST /organizations` reports back.
        $response->assertJsonPath('data.profile_completion.missing_fields', ['phone', 'national_id']);

        $this->postJson('/api/v1/organizations', $this->organizationPayload())
            ->assertForbidden()
            ->assertJsonPath('error.details.missing_fields', ['phone', 'national_id']);
    }

    public function test_me_reports_whether_the_account_has_a_business_and_a_password(): void
    {
        $user = $this->bareAccount();

        Sanctum::actingAs($user);

        // A Google-opened account arriving with no password and no business is
        // the reason both flags exist: the client must not guess them from
        // which inputs happen to be empty.
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.profile_completion.required', true)
            ->assertJsonPath('data.profile_completion.has_organization', false)
            ->assertJsonPath('data.profile_completion.has_password', false);

        $organization = $this->organization('buyer');
        $this->membership($organization, $user, OrganizationMembership::ROLE_OWNER);

        $user->update(['phone' => '+9677' . Str::password(8, false, false)]);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.profile_completion.required', false)
            ->assertJsonPath('data.profile_completion.has_organization', true)
            ->assertJsonPath('data.profile_completion.has_password', false);
    }

    /**
     * The sign-in response carries the same block, so a client never has to make
     * a second call — or keep a second copy of the rule.
     */
    public function test_login_returns_the_same_completion_block_as_me(): void
    {
        [$user, $organization] = $this->accountWithOrganization(null, 'buyer');
        $this->setSetting('account.require_phone_number', '1', 'boolean');

        $login = $this->postJson('/api/v1/auth/login', [
            // The field is `login`, not `email`: the same form accepts either
            // an address or a phone number.
            'login' => $user->email,
            'password' => $this->passwordFor($user),
        ])->assertOk();

        $login->assertJsonPath('data.profile_completion.missing_fields', [])
            ->assertJsonPath('data.profile_completion.has_organization', true)
            ->assertJsonPath('data.profile_completion.has_password', true);

        $this->assertSame(
            $organization->id,
            $login->json('data.active_organization.id'),
            'The session lands in the account\'s business, and the client is told which one.'
        );
    }

    /**
     * The ceiling counts *founding*, not belonging.
     *
     * Being hired as staff at nine companies is somebody else's decision; it is
     * not nine businesses this account opened, and a limit that read the
     * membership rows would turn every employee on the platform into a
     * founding-limit failure.
     */
    public function test_the_ceiling_counts_businesses_the_account_founded_not_ones_it_joined(): void
    {
        $user = $this->user();
        $joined = $this->organization('buyer');
        $this->membership($joined, $user, 'staff');

        $this->setSetting('account.max_organizations_per_account', '1', 'integer');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/organizations', $this->organizationPayload())
            ->assertCreated();

        $this->postJson('/api/v1/organizations', $this->organizationPayload())
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ORGANIZATION_CREATION_LIMIT_REACHED')
            ->assertJsonPath('error.details.limit', 1)
            // One, not two: the business they were added to does not count
            // against them.
            ->assertJsonPath('error.details.founded', 1);
    }

    public function test_a_zero_ceiling_means_no_ceiling(): void
    {
        [$user, ] = $this->accountWithOrganization(null, 'buyer');

        $this->setSetting('account.max_organizations_per_account', '0', 'integer');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/organizations', $this->organizationPayload())->assertCreated();
        $this->postJson('/api/v1/organizations', $this->organizationPayload())->assertCreated();
        $this->postJson('/api/v1/organizations', $this->organizationPayload())->assertCreated();

        $this->assertSame(
            4,
            OrganizationMembership::where('user_id', $user->id)
                ->where('role', OrganizationMembership::ROLE_OWNER)
                ->count()
        );
    }

    /**
     * Platform staff are not held to the self-service ceiling: an operator may
     * have to open records on behalf of businesses for any number of reasons,
     * and a limit meant to stop one person hoarding identities does not apply
     * to the account that administers identities.
     */
    public function test_the_ceiling_does_not_bind_platform_staff(): void
    {
        [$user, ] = $this->accountWithOrganization('SuperAdmin', 'buyer');

        $this->setSetting('account.max_organizations_per_account', '1', 'integer');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/organizations', $this->organizationPayload())->assertCreated();
        $this->postJson('/api/v1/organizations', $this->organizationPayload())->assertCreated();
    }

    /**
     * Whatever a business arrived with, it waits for a reviewer. No field typed
     * at this endpoint grants a verified state (§10).
     */
    public function test_a_newly_founded_business_starts_unverified(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/organizations', $this->organizationPayload())
            ->assertCreated();

        $id = $response->json('data.id');

        $this->assertSame(Organization::STATUS_PENDING, Organization::find($id)->status);
        $this->assertNull(Organization::find($id)->kyc_verified_at);
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $id,
            'user_id' => $user->id,
            'role' => OrganizationMembership::ROLE_OWNER,
            'is_active' => true,
        ]);
    }
}
