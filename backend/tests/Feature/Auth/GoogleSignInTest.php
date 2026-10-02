<?php

namespace Tests\Feature\Auth;

use App\Auth\Models\ExternalIdentity;
use App\Identity\Models\User;
use App\Platform\Services\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeGoogleIdToken;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * Signing in with a Google account, end to end.
 *
 * The subject of every test here is one question: what does this platform
 * accept as proof of who you are? A token signed by Google, addressed to this
 * deployment, for a mailbox Google says it verified — and nothing less. Each
 * rejection that has its own test is a way the platform was shown a piece of
 * paper that looked like a login: a token from a different audience is Google
 * attesting to some other site's user; an unverified address is a mailbox
 * nobody has proven control of; a tampered payload is someone typing an email
 * address into a string.
 *
 * The other half is what a new account *is*. It arrives with no password, no
 * role and no business, because a person's standing comes from a membership
 * row and a provider's assertion says nothing about either. Google answers "who
 * is this"; it does not answer "what may they do", and an account that could
 * trade the moment it was provisioned would be a marketplace anyone with a
 * mailbox could open.
 */
class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;
    use HassalFixtures;
    use FakeGoogleIdToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPlatformDefaults();

        config()->set('hassal.auth.google.jwks_url', self::GOOGLE_TEST_JWKS_URL);

        Cache::flush();

        Http::fake([
            self::GOOGLE_TEST_JWKS_URL => Http::response($this->googleTestJwks(), 200),
        ]);
    }

    private function configureGoogle(bool $enabled = true, bool $autoRegister = true): void
    {
        $settings = app(SystemSettingsService::class);

        $settings->set('auth.google_enabled', $enabled ? '1' : '0', 'boolean', 'auth');
        $settings->set('auth.google_client_id', $this->googleClientId(), 'string', 'auth');
        $settings->set('auth.google_auto_register', $autoRegister ? '1' : '0', 'boolean', 'auth');

        Cache::flush();
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function signIn(array $claims = [], ?string $nonce = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/google', [
            'credential' => $this->googleTestIdToken($claims),
            'nonce' => $nonce ?? $this->googleTestNonce(),
        ]);
    }

    public function test_first_google_sign_in_provisions_an_account_with_nothing_more_than_the_assertion_proves(): void
    {
        $this->configureGoogle();

        $response = $this->signIn([
            'email' => 'owner@najma.test',
            'name' => 'نجلاء الشرعبي',
            'sub' => '1111222233334444',
        ])->assertCreated();

        $user = User::where('email', 'owner@najma.test')->firstOrFail();

        $this->assertSame('نجلاء الشرعبي', $response->json('data.user.name'));
        $this->assertNotEmpty($response->json('data.token'), 'a sign-in that issues no token signed nobody in');

        // No password is invented for a person who never chose one. The column
        // says what is true.
        $this->assertNull($user->password);
        $this->assertSame('active', $user->status);
        $this->assertNotNull($user->email_verified_at, 'Google said it verified the address, and that is what this column means');

        // And no role: not a platform one, and not a company one either. The
        // capability list answers with exactly the declared person standing —
        // an unaffiliated active account may like a listing and ask a public
        // question in its own name — and nothing else. A Google provision is
        // not a route to a company matrix or a platform door.
        $this->assertCount(0, $user->getRoleNames());
        $this->assertCount(0, $user->memberships()->get());
        $this->assertEqualsCanonicalizing(
            \App\Core\Rbac\RbacScope::personCapabilities(),
            $response->json('data.user.permissions'),
            'A fresh unaffiliated account must hold the person capabilities and nothing more.'
        );
        $this->assertFalse((bool) $response->json('data.profile_completion.has_organization'));

        $identity = ExternalIdentity::where('provider_user_id', '1111222233334444')->firstOrFail();
        $this->assertSame($user->id, $identity->user_id);
        $this->assertSame('owner@najma.test', $identity->email);
    }

    public function test_a_repeat_sign_in_is_found_by_the_subject_and_not_by_the_address(): void
    {
        $this->configureGoogle();

        $this->signIn(['email' => 'same@najma.test', 'sub' => '55556666'])->assertCreated();

        // The provider's own address may change on their side. The subject does
        // not, which is why the identity row — not a `where('email', …)` — is
        // what a returning person is recognised by.
        $second = $this->signIn([
            'email' => 'renamed@najma.test',
            'sub' => '55556666',
        ])->assertOk();

        $this->assertSame(1, User::count());
        $this->assertSame(1, ExternalIdentity::count());
        $this->assertSame(User::where('email', 'same@najma.test')->value('id'), $second->json('data.user.id'));

        // The address the provider asserts now is recorded against the identity,
        // so what a reviewer reads later is what was true at the last sign-in.
        $this->assertSame('renamed@najma.test', ExternalIdentity::sole()->email);
    }

    public function test_an_existing_password_account_is_linked_to_the_address_google_verified(): void
    {
        $this->configureGoogle();

        $user = $this->user('صاحب منشأة مسجّلة');
        $user->forceFill(['email' => 'registered@najma.test'])->save();
        $this->membership($this->organization('seller'), $user, 'owner');

        $response = $this->signIn(['email' => 'registered@najma.test', 'sub' => '77778888'])->assertOk();

        $this->assertSame($user->id, $response->json('data.user.id'));
        $this->assertNotNull($user->password, 'linking a provider must not erase the password the account already had');
        $this->assertSame('owner', $response->json('data.organizations.0.role'));
        $this->assertTrue($response->json('data.profile_completion.has_organization'));

        // The identity row is what ties the two together from here on.
        $this->assertSame($user->id, ExternalIdentity::sole()->user_id);
    }

    public function test_an_account_already_bound_to_another_google_account_is_not_rebound(): void
    {
        $this->configureGoogle();

        $user = $this->user('حساب مرتبط');
        $user->forceFill(['email' => 'bound@najma.test'])->save();

        ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => ExternalIdentity::PROVIDER_GOOGLE,
            'provider_user_id' => 'first-google-account',
            'email' => 'bound@najma.test',
            'linked_at' => now(),
        ]);

        // A different subject claiming the same address is a mailbox that moved,
        // and merging would move the business with it.
        $this->signIn(['email' => 'bound@najma.test', 'sub' => 'second-google-account'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'GOOGLE_ACCOUNT_ALREADY_LINKED');

        $this->assertSame('first-google-account', ExternalIdentity::sole()->provider_user_id);
    }

    public function test_provisioning_can_be_turned_off_and_leaves_unknown_addresses_outside(): void
    {
        $this->configureGoogle(autoRegister: false);

        $this->signIn(['email' => 'stranger@najma.test'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'GOOGLE_ACCOUNT_NOT_PROVISIONED');

        $this->assertSame(0, User::count());
    }

    public function test_a_closed_switch_refuses_before_any_token_is_read(): void
    {
        $this->configureGoogle(enabled: false);

        // Nothing is fetched from Google either: a capability that is off does
        // not reach out to the network on someone else's instruction. The
        // assertion below is the proof — `Http::assertNothingSent()` reads the
        // recorded calls, and there are none to record.
        $this->signIn()
            ->assertForbidden()
            ->assertJsonPath('error_code', 'GOOGLE_SIGNIN_DISABLED');

        Http::assertNothingSent();
    }

    public function test_an_enabled_switch_with_no_client_id_refuses_as_unconfigured(): void
    {
        $this->configureGoogle();

        app(SystemSettingsService::class)->set('auth.google_client_id', '', 'string', 'auth');
        Cache::flush();

        // Distinct from "disabled", because the operator's fix is distinct: the
        // switch is already on and what is missing is the deployment's own
        // address in Google's records. No token is read and no key is fetched,
        // so the state of the credential cannot be probed through this door.
        $this->signIn()
            ->assertForbidden()
            ->assertJsonPath('error_code', 'GOOGLE_NOT_CONFIGURED');

        Http::assertNothingSent();
    }

    public function test_a_token_addressed_to_another_application_is_refused(): void
    {
        $this->configureGoogle();

        // Signed by Google, for somebody else. Without this check any service
        // Google has ever issued a token for would be a key into this platform.
        $this->signIn(['aud' => 'some-other-app.example.test'])
            ->assertUnauthorized()
            ->assertJsonPath('error.details.reason', 'GOOGLE_TOKEN_AUDIENCE_REJECTED');

        $this->assertSame(0, User::count());
    }

    public function test_an_address_google_has_not_verified_is_refused(): void
    {
        $this->configureGoogle();

        $this->signIn(['email' => 'nobody@najma.test', 'email_verified' => false])
            ->assertUnauthorized()
            ->assertJsonPath('error.details.reason', 'GOOGLE_EMAIL_UNVERIFIED');

        $this->assertSame(0, User::count());
    }

    public function test_an_expired_token_is_refused_and_a_fresh_one_from_the_same_bytes_is_not(): void
    {
        $this->configureGoogle();

        // Well outside the clock tolerance, so what refuses this is its life
        // having ended rather than the two machines disagreeing by seconds.
        $this->signIn(['exp' => now()->subMinutes(10)->getTimestamp()])
            ->assertUnauthorized()
            ->assertJsonPath('error.details.reason', 'GOOGLE_TOKEN_EXPIRED');

        // The very same claims with a live expiry pass, so what failed above is
        // the expiry and not the signature, the audience or the address. The
        // account is new here: the refused attempt created nothing.
        $this->signIn()->assertCreated();
    }

    public function test_a_token_that_expired_seconds_ago_is_still_honoured(): void
    {
        $this->configureGoogle();

        // This is the tolerance doing its job, so it is written down as a test:
        // two servers whose clocks disagree by half a minute must not lock a
        // person out of their business. It is minutes, not hours, and the
        // verifier clamps it — see the previous test's ten-minute token.
        $this->signIn(['exp' => now()->subSeconds(30)->getTimestamp()])
            ->assertCreated();
    }

    public function test_a_token_issued_from_the_future_is_refused(): void
    {
        $this->configureGoogle();

        $this->signIn(['iat' => now()->addMinutes(30)->getTimestamp()])
            ->assertUnauthorized()
            ->assertJsonPath('error.details.reason', 'GOOGLE_TOKEN_NOT_YET_VALID');

        $this->assertSame(0, User::count());
    }

    public function test_a_token_from_another_issuer_is_refused_even_signed_by_the_same_key(): void
    {
        $this->configureGoogle();

        $this->signIn(['iss' => 'https://notgoogle.example.test'])
            ->assertUnauthorized()
            ->assertJsonPath('error.details.reason', 'GOOGLE_TOKEN_ISSUER_REJECTED');
    }

    public function test_a_token_carried_from_another_sign_in_request_is_refused_by_the_nonce(): void
    {
        $this->configureGoogle();

        // The browser generated a different nonce for this request, so this
        // token — validly signed, correctly addressed — was asked for by
        // someone else.
        $this->signIn(nonce: 'a-different-nonce-value-123456')
            ->assertUnauthorized()
            ->assertJsonPath('error.details.reason', 'GOOGLE_TOKEN_NONCE_MISMATCH');
    }

    public function test_a_payload_signed_by_another_key_is_refused_however_it_is_addressed(): void
    {
        $this->configureGoogle();

        $response = $this->postJson('/api/v1/auth/google', [
            'credential' => $this->googleTestIdTokenSignedByOtherKey(),
            'nonce' => $this->googleTestNonce(),
        ])->assertUnauthorized();

        $this->assertSame('GOOGLE_TOKEN_SIGNATURE_INVALID', $response->json('error.details.reason'));
        $this->assertSame(0, User::count());
    }

    public function test_google_cannot_log_into_an_account_the_platform_has_suspended(): void
    {
        $this->configureGoogle();

        $user = $this->user('حساب موقوف');
        $user->forceFill(['email' => 'suspended@najma.test', 'status' => 'suspended'])->save();

        $this->signIn(['email' => 'suspended@najma.test'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ACCOUNT_INACTIVE');

        // The provider's word about identity is not this platform's word about
        // access, and the two are decided in different places.
        $this->assertSame(0, ExternalIdentity::count());
    }

    public function test_a_passwordless_account_cannot_be_probed_through_the_password_form(): void
    {
        $this->configureGoogle();

        $this->signIn(['email' => 'googleonly@najma.test'])->assertCreated();

        // `password` is NULL on that row, and a comparison against NULL is a
        // fatal in PHP 8. An error page would confirm the account exists; this
        // answers exactly as a wrong password does.
        $this->postJson('/api/v1/auth/login', [
            'login' => 'googleonly@najma.test',
            'password' => 'anything-at-all',
        ])->assertUnauthorized()->assertJsonPath('error_code', 'INVALID_CREDENTIALS');
    }

    public function test_a_passwordless_account_can_set_its_first_password_and_then_sign_in_with_it(): void
    {
        $this->configureGoogle();

        $token = $this->signIn(['email' => 'firstpassword@najma.test'])->json('data.token');

        // It is not asked for a current password it never had.
        $this->withToken($token)->postJson('/api/v1/auth/password', [
            'password' => 'another-secret',
            'password_confirmation' => 'another-secret',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'login' => 'firstpassword@najma.test',
            'password' => 'another-secret',
        ])->assertOk()->assertJsonPath('data.profile_completion.has_password', true);
    }

    public function test_a_suspended_google_account_still_cannot_read_its_own_profile(): void
    {
        $this->configureGoogle();

        $token = $this->signIn(['email' => 'willbesuspended@najma.test'])->json('data.token');
        $this->assertNotNull($token);

        User::where('email', 'willbesuspended@najma.test')->update(['status' => 'suspended']);

        // A session opened through any door obeys the same account state.
        Cache::flush();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden();
    }
}
