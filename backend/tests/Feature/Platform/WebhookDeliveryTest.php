<?php

namespace Tests\Feature\Platform;

use App\Platform\Models\Webhook;
use App\Platform\Models\WebhookDelivery;
use App\Platform\Services\WebhookDispatcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * Webhooks are the one place where the platform makes an outbound request on
 * somebody else's instructions, and the only place where a signature is the
 * difference between "this came from HASSAL" and "this came from somebody who
 * read our payload format".
 *
 * So the tests attack both: an endpoint inside the network must be refused at
 * registration *and* never posted to later (a row written before the policy
 * existed is the realistic case), the signing secret must be visible exactly
 * once — no view at all, one create response — and the signature must verify
 * for the bytes that were sent and fail for anything else, including a
 * captured delivery replayed after the window closes.
 */
class WebhookDeliveryTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
        $this->setFlag('webhooks', true);
    }

    /**
     * @return array{0: \App\Identity\Models\User, 1: \App\Organizations\Models\Organization}
     */
    protected function sellerAdmin(): array
    {
        return $this->accountWithOrganization('SellerAdmin', 'seller', 'مصنع تجربي');
    }

    public function test_a_registered_endpoint_returns_its_signing_secret_once_and_never_again(): void
    {
        [$user, $organization] = $this->sellerAdmin();
        Sanctum::actingAs($user);

        $response = $this->withHeaders($this->withTenant($organization))
            ->postJson('/api/v1/webhooks', [
                'endpoint_url' => 'https://hooks.partners.example/hassal',
                'events' => ['deal.status_changed', 'inventory.reserved'],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.endpoint_url', 'https://hooks.partners.example/hassal')
            ->assertJsonPath('data.events', ['deal.status_changed', 'inventory.reserved']);

        $secret = $response->json('data.secret');
        $this->assertIsString($secret);
        $this->assertMatchesRegularExpression('/^whsec_[a-f0-9]{64}$/', $secret);

        $webhook = Webhook::sole();
        $this->assertSame($secret, $webhook->secret, 'The secret handed out must be the one deliveries are signed with.');

        // Keeping the secret out of JSON is not the same as keeping it out of
        // the database. Read the column past the model's cast: a dump of this
        // table must not hand anybody the key that signs this tenant's events.
        $raw = DB::table('webhooks')->where('id', $webhook->id)->value('secret');
        $this->assertNotSame($secret, $raw);
        $this->assertDoesNotMatchRegularExpression('/^whsec_/', (string) $raw, 'The signing key must not be stored in plain text.');
        $this->assertSame($secret, $webhook->fresh()->secret, 'The stored value must decrypt back to the key that was handed out.');

        // The list endpoint is what a screen polls. If the secret appeared
        // there it would sit in browser history, logs and every screenshot of
        // the admin console.
        $this->withHeaders($this->withTenant($organization))
            ->getJson('/api/v1/webhooks')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $webhook->id)
            ->assertJsonMissingPath('data.0.secret');
    }

    public function test_an_endpoint_inside_the_network_is_refused_and_nothing_is_stored(): void
    {
        [$user, $organization] = $this->sellerAdmin();
        Sanctum::actingAs($user);

        foreach (['https://169.254.169.254/latest/meta-data/', 'https://127.0.0.1:9200/_doc', 'http://hooks.partners.example/webhook'] as $url) {
            $response = $this->withHeaders($this->withTenant($organization))
                ->postJson('/api/v1/webhooks', ['endpoint_url' => $url, 'events' => ['deal.status_changed']]);

            $this->assertApiValidationErrors($response, ['endpoint_url']);
            $this->assertMatchesRegularExpression(
                '/private|https|internal|fully-qualified/i',
                $this->apiValidationMessage($response, 'endpoint_url'),
                "Refused [{$url}] for an unexpected reason."
            );
        }

        $this->assertSame(0, Webhook::count(), 'A refused endpoint must not be stored for later.');
    }

    public function test_an_event_this_platform_never_sends_cannot_be_subscribed_to(): void
    {
        // `payment.verified` is the instructive one: it reads like a marketplace
        // event, and a business that registered for it would wait forever,
        // because HASSAL does not see money change hands. The catalogue on
        // `WebhookDispatcherService` is what a subscription is allowed to name.
        [$user, $organization] = $this->sellerAdmin();
        Sanctum::actingAs($user);

        $response = $this->withHeaders($this->withTenant($organization))
            ->postJson('/api/v1/webhooks', [
                'endpoint_url' => 'https://hooks.partners.example/hassal',
                'events' => ['not an event name'],
            ]);

        $this->assertApiValidationErrors($response, ['events.0']);

        $second = $this->withHeaders($this->withTenant($organization))
            ->postJson('/api/v1/webhooks', [
                'endpoint_url' => 'https://hooks.partners.example/hassal',
                'events' => ['payment.verified'],
            ]);

        $this->assertApiValidationErrors($second, ['events.0']);
        $this->assertSame(0, Webhook::count());
    }

    public function test_the_capability_flag_closes_the_whole_surface(): void
    {
        $this->setFlag('webhooks', false);

        [$user, $organization] = $this->sellerAdmin();
        Sanctum::actingAs($user);

        $this->withHeaders($this->withTenant($organization))
            ->postJson('/api/v1/webhooks', [
                'endpoint_url' => 'https://hooks.partners.example/hassal',
                'events' => ['deal.status_changed'],
            ])
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'FEATURE_DISABLED');

        $this->withHeaders($this->withTenant($organization))
            ->getJson('/api/v1/webhooks')
            ->assertStatus(503);

        $this->assertSame(0, Webhook::count());
    }

    public function test_a_role_without_the_integration_permission_cannot_register_an_endpoint(): void
    {
        // A colleague who keeps the shelf does not decide which external systems
        // receive this organization's event stream. That is what `api.manage`
        // reserves to the owner, and the standing has to be a real one: an
        // account built as the company's owner holds it, which is the whole
        // point of making the grant come from the membership row.
        $organization = $this->organization('seller', 'متجر تجربي');
        $user = $this->memberOf($organization, 'staff');
        Sanctum::actingAs($user);

        $this->withHeaders($this->withTenant($organization))
            ->postJson('/api/v1/webhooks', [
                'endpoint_url' => 'https://hooks.partners.example/hassal',
                'events' => ['deal.status_changed'],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PERMISSION_DENIED')
            ->assertJsonPath('error.details.required_permission', 'api.manage');

        $this->assertSame(0, Webhook::count());
    }

    public function test_one_business_cannot_reach_another_business_webhook_or_its_delivery_log(): void
    {
        [$owner, $organization] = $this->sellerAdmin();
        [$stranger, $otherOrganization] = $this->sellerAdmin();

        $webhook = Webhook::create([
            'organization_id' => $organization->id,
            'endpoint_url' => 'https://hooks.partners.example/hassal',
            'secret' => 'whsec_' . str_repeat('a', 32),
            'events' => ['*'],
            'is_active' => true,
        ]);

        Sanctum::actingAs($stranger);
        $headers = $this->withTenant($otherOrganization);

        $this->withHeaders($headers)->getJson("/api/v1/webhooks/{$webhook->id}/deliveries")->assertStatus(404);
        $this->withHeaders($headers)->postJson("/api/v1/webhooks/{$webhook->id}/ping")->assertStatus(404);

        // Even with the right tenant header, the intruder's own list stays empty.
        $this->withHeaders($headers)->getJson('/api/v1/webhooks')->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_a_stored_internal_endpoint_is_never_posted_to_even_when_pinged(): void
    {
        [$user, $organization] = $this->sellerAdmin();
        Sanctum::actingAs($user);

        // Written straight to the table: this is a row created before the
        // address policy existed, or by an operator who has since turned the
        // development escape hatch off.
        $webhook = Webhook::create([
            'organization_id' => $organization->id,
            'endpoint_url' => 'https://127.0.0.1:8080/internal-hook',
            'secret' => 'whsec_' . str_repeat('b', 32),
            'events' => ['*'],
            'is_active' => true,
        ]);

        Http::fake();

        $this->withHeaders($this->withTenant($organization))
            ->postJson("/api/v1/webhooks/{$webhook->id}/ping")
            ->assertStatus(200)
            ->assertJsonPath('data.status', WebhookDelivery::STATUS_BLOCKED);

        Http::assertNothingSent();

        $this->assertSame(1, (int) $webhook->fresh()->failure_count);
    }

    public function test_a_delivery_is_signed_over_the_exact_bytes_sent_and_verifies(): void
    {
        Http::fake(['hooks.partners.example/*' => Http::response('ok', 200)]);

        [$user, $organization] = $this->sellerAdmin();
        Sanctum::actingAs($user);

        $webhook = Webhook::create([
            'organization_id' => $organization->id,
            'endpoint_url' => 'https://hooks.partners.example/hassal',
            'secret' => 'whsec_' . str_repeat('c', 32),
            'events' => ['*'],
            'is_active' => true,
        ]);

        $delivery = $this->withHeaders($this->withTenant($organization))
            ->postJson("/api/v1/webhooks/{$webhook->id}/ping")
            ->assertStatus(200)
            ->assertJsonPath('data.status', WebhookDelivery::STATUS_DELIVERED)
            ->json('data');

        Http::assertSent(function ($request) {
            return $request->isJson()
                && preg_match('/^t=\d+,v1=[a-f0-9]{64}$/', $request->header('X-Hassal-Signature')[0] ?? '') === 1;
        });

        $sent = Http::recorded()[0][0];
        $body = $sent->body();
        $header = $sent->header('X-Hassal-Signature')[0];

        $dispatcher = app(WebhookDispatcherService::class);

        $this->assertTrue(
            $dispatcher->verify($header, $body, $webhook->secret),
            'A receiver with the secret must be able to verify a genuine delivery.'
        );

        // Same valid signature, one byte changed in the payload: the amount on
        // the invoice is exactly the kind of number worth tampering with.
        $this->assertFalse($dispatcher->verify($header, str_replace('ping', 'pang', $body), $webhook->secret));

        // Right signature, wrong secret — i.e. another tenant's key.
        $this->assertFalse($dispatcher->verify($header, $body, 'whsec_' . str_repeat('d', 32)));

        // A captured delivery replayed after the window is not a delivery.
        $this->assertFalse(
            $dispatcher->verify($header, $body, $webhook->secret, -1),
            'The replay window has to be bounded.'
        );

        $this->assertFalse($dispatcher->verify('v1=deadbeef', $body, $webhook->secret), 'A header without a timestamp must not pass.');

        // The recorded row carries the signature the receiver was handed.
        $this->assertSame($delivery['signature'], WebhookDelivery::sole()->signature);
    }

    public function test_a_receiver_that_errors_is_recorded_as_failed_not_delivered(): void
    {
        Http::fake(['hooks.partners.example/*' => Http::response('server exploded', 500)]);

        [$user, $organization] = $this->sellerAdmin();
        Sanctum::actingAs($user);

        $webhook = Webhook::create([
            'organization_id' => $organization->id,
            'endpoint_url' => 'https://hooks.partners.example/hassal',
            'secret' => 'whsec_' . str_repeat('e', 32),
            'events' => ['*'],
            'is_active' => true,
        ]);

        $this->withHeaders($this->withTenant($organization))
            ->postJson("/api/v1/webhooks/{$webhook->id}/ping")
            ->assertStatus(200)
            ->assertJsonPath('data.status', WebhookDelivery::STATUS_FAILED)
            ->assertJsonPath('data.response_status', 500);

        Http::assertSent(fn ($request) => $request->isJson());
        $this->assertCount(1, Http::recorded());
        $this->assertSame(1, (int) $webhook->fresh()->failure_count);
        $this->assertNull($webhook->fresh()->last_triggered_at);
    }

    public function test_a_retry_updates_the_one_delivery_row_and_stops_at_the_attempt_cap(): void
    {
        Http::fake(['hooks.partners.example/*' => Http::response('server exploded', 500)]);

        $webhook = Webhook::create([
            'organization_id' => $this->organization('seller')->id,
            'endpoint_url' => 'https://hooks.partners.example/hassal',
            'secret' => 'whsec_' . str_repeat('a', 32),
            'events' => ['*'],
            'is_active' => true,
        ]);

        app(WebhookDispatcherService::class)->sendDelivery($webhook, 'deal.status_changed', ['deal_id' => 7]);

        $this->assertSame(1, WebhookDelivery::count());
        $this->assertSame(1, (int) WebhookDelivery::sole()->attempt);

        // A retry used to create a second row, and that row was itself eligible
        // for five retries: one dead endpoint kept manufacturing retryable
        // work, and the receiver saw a new event id each time.
        $this->artisan('app:process-webhooks')->assertExitCode(0);
        $this->artisan('app:process-webhooks')->assertExitCode(0);

        $delivery = WebhookDelivery::sole();
        $this->assertSame(3, (int) $delivery->attempt, 'Each scheduler tick spends one attempt on the same row.');
        $this->assertSame(3, count(Http::recorded()), 'The event was posted three times, not three rows\' worth.');

        // The re-signed attempt has to be verifiable: keeping the old signature
        // with a new timestamp, or the old timestamp with a new signature, both
        // fail a receiver that honours the replay window.
        $last = Http::recorded()[2][0];
        $this->assertTrue(
            app(WebhookDispatcherService::class)->verify(
                $last->header('X-Hassal-Signature')[0],
                $last->body(),
                $webhook->secret
            ),
            'A retried delivery must carry a signature that matches the bytes sent.'
        );
        $this->assertStringEndsWith(
            ',v1=' . $delivery->signature,
            $last->header('X-Hassal-Signature')[0],
            'The row must store the signature the receiver was actually handed.'
        );
    }

    public function test_redirects_are_not_followed_so_an_approved_url_cannot_bounce_inward(): void
    {
        // The recorder keeps the PSR request but not the transfer options, so
        // the fake itself has to capture them: with a stubbed transport nothing
        // would follow a redirect anyway, and the test would pass while proving
        // nothing.
        $options = null;

        Http::fake(function ($request, array $transferOptions) use (&$options) {
            $options = $transferOptions;

            return Http::response('ok', 200);
        });

        $webhook = Webhook::create([
            'organization_id' => $this->organization('seller')->id,
            'endpoint_url' => 'https://hooks.partners.example/hassal',
            'secret' => 'whsec_' . str_repeat('f', 32),
            'events' => ['*'],
            'is_active' => true,
        ]);

        app(WebhookDispatcherService::class)->sendDelivery($webhook, 'system.ping', ['message' => 'x']);

        $this->assertIsArray($options, 'The dispatcher never reached the HTTP client.');
        $this->assertFalse(
            $options['allow_redirects'] ?? true,
            'Following a redirect would post a signed payload to an address nobody approved.'
        );
    }
}
