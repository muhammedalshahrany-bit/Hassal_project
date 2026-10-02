<?php

namespace Tests\Feature\Auth;

use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationMembership;
use App\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * Accounts, not demo personas.
 *
 * The suite used to sign in as `seller@alwataniya.sa` from seeded demo data —
 * a Saudi pilot that is not this product, and credentials committed to the
 * repository. Every account here is created by the test and its password
 * exists only in memory for the length of the run (§62).
 */
class AuthTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    public function test_a_person_can_register_and_receive_a_working_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'مشتري تجريبي',
            'email' => 'buyer-' . uniqid() . '@example.test',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.name', 'مشتري تجريبي')
            ->assertJsonStructure(['data' => ['token']]);

        // The returned token must actually authenticate — a token that only
        // exists in the response body is a broken login dressed as success.
        $this->assertDatabaseHas('users', ['email' => $response->json('data.user.email')]);

        $this->withToken($response->json('data.token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $response->json('data.user.email'));
    }

    public function test_an_issued_token_expires_after_the_configured_lifetime(): void
    {
        $minutes = (int) config('hassal.auth.token_ttl_minutes');
        $this->assertGreaterThan(0, $minutes, 'The deployment must declare a token lifetime.');

        $token = $this->postJson('/api/v1/auth/register', [
            'name' => 'مستخدم مهلة',
            'email' => 'ttl-' . uniqid() . '@example.test',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->json('data.token');

        $this->assertNotNull($token);

        $record = PersonalAccessToken::sole();

        $this->assertNotNull($record->expires_at, 'A session token was issued with no expiry at all.');
        $this->assertTrue(
            $record->expires_at->between(now()->addMinutes($minutes - 1), now()->addMinutes($minutes + 1)),
            'The declared lifetime is not the lifetime the token actually carries.'
        );
    }

    public function test_a_token_stops_working_once_its_lifetime_ends(): void
    {
        config()->set('hassal.auth.token_ttl_minutes', 1);

        [$user, $organization] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'منشأة مهلة');

        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => $this->passwordFor($user),
        ])->json('data.token');

        $this->assertNotNull(PersonalAccessToken::sole()->expires_at);

        // Only one authenticated call per test: a feature test keeps one
        // container across requests, and the guard caches the user it resolved,
        // so a "before" request here would make the "after" request meaningless.
        $this->travel(2)->minutes();

        $this->withToken($token)
            ->withHeaders($this->withTenant($organization))
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->travelBack();
    }

    /**
     * The second door is shut, and the first one is walked through.
     *
     * Registration used to take an optional `organization_name` and, when it
     * arrived, create a business with the caller as owner. That path checked
     * nothing the real one checked — no collision on the commercial
     * registration number, no `legal.*` requirement the operator switched on, no
     * limit on how many businesses one account may open — and because it asked
     * for less it was the door everyone came through, which made every rule on
     * the other door a rule that could be signed around at the moment a business
     * was born.
     *
     * So this test is the whole journey in the order the platform now enforces
     * it: a person is created, the business is created through its own endpoint
     * against its own rules, and the founder is the owner of the result.
     */
    public function test_a_business_is_created_after_registration_and_not_inside_it(): void
    {
        $email = 'owner-' . uniqid() . '@example.test';

        $registration = $this->postJson('/api/v1/auth/register', [
            'name' => 'صاحب منشأة',
            'email' => $email,
            'phone' => '+9677' . Str::password(8, false, false),
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
            // The shape the old form sent, on a route that no longer accepts it.
            'organization_name' => 'مؤسسة تجريبية',
            'organization_type' => 'seller',
            'commercial_registration_number' => 'CR-AUTH-' . uniqid(),
            'vat_number' => 'VAT-AUTH-' . uniqid(),
        ]);

        $registration->assertStatus(422);

        foreach (['organization_name', 'organization_type', 'commercial_registration_number', 'vat_number'] as $field) {
            $this->assertArrayHasKey($field, (array) $registration->json('error.details'));
        }

        // Refused rather than ignored: a client that quietly stops being heard
        // keeps believing it was.
        $this->assertStringContainsString(
            '/organizations',
            (string) $registration->json('error.details.organization_name.0')
        );

        // And nothing at all was written: the refusal is the whole answer, not a
        // warning after a half-completed signup.
        $this->assertSame(0, User::where('email', $email)->count());
        $this->assertSame(0, Organization::where('commercial_registration_number', 'like', 'CR-AUTH-%')->count());

        // The same request without the business keys is a person, and a person
        // alone: no membership row, because nothing here has proven a company.
        $clean = $this->postJson('/api/v1/auth/register', [
            'name' => 'صاحب منشأة',
            'email' => 'founder-' . uniqid() . '@example.test',
            'phone' => '+9677' . Str::password(8, false, false),
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertCreated();

        $userId = $clean->json('data.user.id');
        $this->assertSame(0, OrganizationMembership::where('user_id', $userId)->count());
        $this->assertTrue((bool) $clean->json('data.profile_completion.required'));
        $this->assertFalse((bool) $clean->json('data.profile_completion.has_organization'));

        $token = $clean->json('data.token');

        $cr = 'CR-AUTH-' . strtoupper(substr(uniqid(), -8));

        $organization = $this->withToken($token)->postJson('/api/v1/organizations', [
            'name_ar' => 'مؤسسة النور للمواد الغذائية',
            'name_en' => 'Al Noor Food Trading',
            'type' => 'seller',
            'commercial_registration_number' => $cr,
            'vat_number' => 'VAT-AUTH-' . strtoupper(substr(uniqid(), -8)),
        ])->assertCreated();

        $organizationId = $organization->json('data.id');

        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'role' => 'owner',
            'is_active' => true,
        ]);

        // A new business is not verified by the platform on its own say-so.
        $this->assertSame(
            Organization::STATUS_PENDING,
            Organization::find($organizationId)->status
        );
    }

    public function test_a_weak_password_is_rejected_rather_than_stored(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => 'weak-' . uniqid() . '@example.test',
            'password' => '123',
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['password']]]);
    }

    public function test_an_application_that_omits_its_identity_is_told_what_is_missing(): void
    {
        // Used to be `sometimes` on both fields: the request passed validation,
        // the controller filled the name with "New User", and a password that
        // was never supplied reached User::create() as an undefined key.
        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'ghost-' . uniqid() . '@example.test',
        ]);

        $this->assertApiValidationErrors($response, ['name', 'password']);
        $this->assertSame(0, User::where('name', 'New User')->count());
    }

    public function test_a_password_confirmation_has_to_match_the_password(): void
    {
        $email = 'mismatch-' . uniqid() . '@example.test';

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'مستخدم غير متطابق',
            'email' => $email,
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-different-long-password',
        ]);

        $this->assertApiValidationErrors($response, ['password']);
        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    public function test_password_recovery_answers_the_same_question_whether_or_not_an_email_exists(): void
    {
        $user = $this->user();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody-' . uniqid() . '@nowhere.test']);

        $known->assertOk();
        $unknown->assertOk();

        // The same message, not merely the same status: a form that says "if
        // this email is registered…" on one branch and "no such account" on the
        // other is an oracle over a list of who trades here.
        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertStringNotContainsString('غير مسجل', (string) $unknown->json('message'));
    }

    public function test_login_accepts_either_email_or_phone_and_never_leaks_the_password(): void
    {
        [$user, $organization] = $this->accountWithOrganization('BuyerAdmin', 'buyer');
        $user->forceFill(['phone' => '+9677' . rand(10000000, 99999999)])->save();

        $byEmail = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => $this->passwordFor($user),
        ]);
        $byEmail->assertOk()->assertJsonPath('data.user.id', $user->id);

        // What must not travel is the credential: neither the hash in the column
        // nor the words the person typed. The body does carry
        // `profile_completion.has_password`, and that is a different thing — a
        // boolean about the caller's own account, needed by the screen to decide
        // whether to offer "change password" or "set a password".
        $this->assertNull($byEmail->json('data.user.password'));
        $this->assertStringNotContainsString(
            (string) $user->getAuthPassword(),
            $byEmail->getContent(),
            'The stored password hash appeared in a login response.'
        );
        $this->assertStringNotContainsString(
            $this->passwordFor($user),
            (string) $byEmail->getContent()
        );

        $byPhone = $this->postJson('/api/v1/auth/login', [
            'login' => $user->phone,
            'password' => $this->passwordFor($user),
        ]);
        $byPhone->assertOk()->assertJsonPath('data.user.id', $user->id);

        // Logging in lists the businesses this person belongs to, so the client
        // can choose a context instead of guessing an organization id.
        $byPhone->assertJsonPath('data.organizations.0.id', $organization->id);
    }

    public function test_wrong_password_gets_the_same_answer_as_a_wrong_email(): void
    {
        [$user, ] = $this->accountWithOrganization('BuyerAdmin', 'buyer');

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'not-the-password-at-all',
        ])->assertStatus(401)->assertJsonPath('error_code', 'INVALID_CREDENTIALS');

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'login' => 'nobody-' . uniqid() . '@example.test',
            'password' => 'not-the-password-at-all',
        ])->assertStatus(401)->assertJsonPath('error_code', 'INVALID_CREDENTIALS');

        // Identical error payloads: the endpoint must not tell an attacker
        // whether the account exists.
        $this->assertSame($wrongPassword->json('error'), $unknownEmail->json('error'));
    }

    public function test_a_suspended_person_cannot_sign_in(): void
    {
        [$user, ] = $this->accountWithOrganization('BuyerAdmin', 'buyer');
        $user->forceFill(['status' => 'suspended'])->save();

        $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => $this->passwordFor($user),
        ])->assertStatus(403)->assertJsonPath('error_code', 'ACCOUNT_INACTIVE');
    }

    public function test_logging_out_invalidates_the_token_that_was_used(): void
    {
        [$user, ] = $this->accountWithOrganization('BuyerAdmin', 'buyer');

        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        // One container serves all the requests in a test, so the guard would
        // otherwise answer from the session it built during the logout call.
        $this->asFresh();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_person_cannot_enter_a_business_they_do_not_belong_to(): void
    {
        [$intruder, ] = $this->accountWithOrganization('BuyerAdmin', 'buyer');
        [, $someoneElses] = $this->accountWithOrganization('SellerAdmin', 'seller');

        Sanctum::actingAs($intruder);

        $this->postJson('/api/v1/auth/switch-organization', [
            'organization_id' => $someoneElses->id,
        ])->assertForbidden()->assertJsonPath('error_code', 'ORGANIZATION_NOT_MEMBER');

        $this->assertDatabaseMissing('organization_memberships', [
            'organization_id' => $someoneElses->id,
            'user_id' => $intruder->id,
        ]);
    }
}
