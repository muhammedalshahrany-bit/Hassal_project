<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * Every `/api/v1` call is bounded, and the ceiling is an operator's number.
 *
 * Before this, `throttle` existed on four public auth routes only. Anything a
 * signed-in account did — a script looping over `GET /demands`, a bug that
 * retries a list forever — reached the database unbounded, and the platform's
 * only record of it was an ordinary audit row. Three ceilings are proven here
 * because they answer three different attacks: one account, one address, and
 * one tenancy spending its budget through several staff logins.
 *
 * The numbers are asserted to come from `system_settings`, not from code, since
 * an operator lowering a ceiling after seeing traffic must be able to do it
 * without a deployment.
 */
class ApiRateLimitTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    public function test_a_signed_in_account_gets_refused_once_it_passes_its_configured_ceiling(): void
    {
        [$user, $organization] = $this->accountWithOrganization('BuyerAdmin');

        // Deliberately not the catalog default: the point is that the row is
        // what the limiter reads.
        $this->setSetting('security.requests_per_minute', '3', 'integer');

        Sanctum::actingAs($user);
        $headers = $this->withTenant($organization);

        for ($call = 1; $call <= 3; $call++) {
            $this->getJson('/api/v1/notifications', $headers)->assertOk();
        }

        $refused = $this->getJson('/api/v1/notifications', $headers)->assertStatus(429);

        $refused->assertJsonPath('error_code', 'TOO_MANY_REQUESTS')
            ->assertJsonStructure(['success', 'error' => ['code', 'message', 'details' => ['retry_after']]]);

        $this->assertNotNull(
            $refused->headers->get('Retry-After'),
            'a 429 without Retry-After leaves a client guessing and hammering again'
        );
    }

    public function test_the_ceiling_moves_with_the_setting_so_an_operator_can_lower_it_live(): void
    {
        [$user, $organization] = $this->accountWithOrganization('BuyerAdmin');
        Sanctum::actingAs($user);
        $headers = $this->withTenant($organization);

        $this->setSetting('security.requests_per_minute', '6', 'integer');
        for ($call = 1; $call <= 6; $call++) {
            $this->getJson('/api/v1/notifications', $headers)->assertOk();
        }
        $this->getJson('/api/v1/notifications', $headers)->assertStatus(429);

        // A fresh application instance per test keeps the array cache honest, so
        // prove the other direction on its own: raise the ceiling and the same
        // seventh call is allowed again.
        $this->flushRateLimitCounters();
        $this->setSetting('security.requests_per_minute', '10', 'integer');
        $this->getJson('/api/v1/notifications', $headers)->assertOk();
    }

    public function test_anonymous_traffic_is_bounded_per_address(): void
    {
        $this->setSetting('security.guest_requests_per_minute', '2', 'integer');

        $this->getJson('/api/v1/marketplace/categories')->assertOk();
        $this->getJson('/api/v1/marketplace/categories')->assertOk();

        $this->getJson('/api/v1/marketplace/categories')
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'TOO_MANY_REQUESTS');
    }

    public function test_accounts_of_one_organization_share_a_budget(): void
    {
        $organization = $this->organization('buyer');
        $first = $this->user('موظف أول');
        $second = $this->user('موظف ثانٍ');
        $this->membership($organization, $first, 'admin');
        $this->membership($organization, $second, 'admin');
        $first->assignRole('BuyerAdmin');
        $second->assignRole('BuyerAdmin');

        // Per-account ceilings that would each allow everything, and an
        // organization ceiling of two.
        $this->setSetting('security.requests_per_minute', '50', 'integer');
        $this->setSetting('security.organization_requests_per_minute', '2', 'integer');

        $headers = $this->withTenant($organization);

        Sanctum::actingAs($first);
        $this->getJson('/api/v1/notifications', $headers)->assertOk();

        Sanctum::actingAs($second);
        $this->getJson('/api/v1/notifications', $headers)->assertOk();

        Sanctum::actingAs($first);
        $this->getJson('/api/v1/notifications', $headers)
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'TOO_MANY_REQUESTS');
    }

    public function test_the_public_auth_routes_keep_their_own_tighter_limit(): void
    {
        // The route-level limiter must survive the group limiter, or a login
        // brute-force gains a 300-per-minute budget instead of losing one.
        $this->setSetting('security.requests_per_minute', '300', 'integer');
        $this->setSetting('security.guest_requests_per_minute', '300', 'integer');

        $refused = null;
        for ($attempt = 1; $attempt <= 12; $attempt++) {
            $res = $this->postJson('/api/v1/auth/login', [
                'email'    => 'لا-يوجد@example.test',
                'password' => 'wrong-password-here',
            ]);
            if ($res->status() === 429) {
                $refused = $res;
                break;
            }
        }

        $this->assertNotNull($refused, 'throttle:10,1 on POST /auth/login should have refused before the twelfth attempt');
        $this->assertSame('TOO_MANY_REQUESTS', $refused->json('error_code'));
    }

    /**
     * The array cache lives for the whole test method, so a counter that has
     * already tripped would otherwise decide the next assertion.
     */
    private function flushRateLimitCounters(): void
    {
        cache()->clear();
    }
}
