<?php

namespace Tests\Feature;

use App\Models\FeatureFlag;
use App\Services\ApiKeyAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * What a key really buys its holder today.
 *
 * `POST /api-keys` had no capability in front of it while the flag's default
 * still said on, so a business could generate a credential, paste it into a
 * partner's settings and both would assume an integration existed. Nothing in
 * this application authenticates a key — `ApiKeyAuthenticationService::
 * authenticate()` has no request-path caller — so the honest shape is: the
 * console can list and revoke, cannot issue while the capability is off, and is
 * told through the payload when the thing it just made does not work.
 *
 * The third test is the one that matters most: with the switch deliberately
 * turned on, the response still reports `key_authentication_ready: false`,
 * because that signal is read from the route table and not from the flag. A
 * console cannot talk itself into believing an integration exists.
 */
class ApiKeyCapabilityTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    public function test_the_console_can_list_keys_and_is_told_no_route_accepts_them(): void
    {
        [$user, $organization] = $this->accountWithOrganization('SuperAdmin');
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/v1/api-keys', $this->withTenant($organization))->assertOk();

        $res->assertJsonPath('meta.key_authentication_ready', false);
        $this->assertIsArray($res->json('data'));
    }

    public function test_issuing_is_refused_while_the_capability_is_off(): void
    {
        [$user, $organization] = $this->accountWithOrganization('SuperAdmin');
        Sanctum::actingAs($user);

        $this->assertFalse((bool) FeatureFlag::where('key', 'api_keys')->value('is_enabled'));

        $this->postJson(
            '/api/v1/api-keys',
            ['name' => 'مفتاح شريك', 'scopes' => ['orders.read']],
            $this->withTenant($organization) + ['X-Idempotency-Key' => 'issue-off']
        )
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'FEATURE_DISABLED');
    }

    public function test_an_issued_key_still_reports_that_no_route_accepts_it(): void
    {
        [$user, $organization] = $this->accountWithOrganization('SuperAdmin');
        Sanctum::actingAs($user);

        // An operator switches it on. Issuing then works, because that is what
        // the switch is for — but readiness is not the switch.
        $this->setFlag('api_keys', true);

        $res = $this->postJson(
            '/api/v1/api-keys',
            ['name' => 'مفتاح شريك', 'scopes' => ['orders.read']],
            $this->withTenant($organization) + ['X-Idempotency-Key' => 'issue-on']
        )->assertCreated();

        $this->assertNotEmpty($res->json('data.secret'));
        $this->assertFalse(
            $res->json('meta.key_authentication_ready'),
            'nothing in the router accepts a key, so the payload must not imply one does'
        );

        $this->assertFalse(
            app(ApiKeyAuthenticationService::class)->acceptedByAnyRoute(),
            'the probe reads the route table, not the flag'
        );
    }
}
