<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use App\Models\Webhook;
use App\Services\WebhookDispatcherService;
use Tests\TestCase;

class WebhookAndApiKeyIntegrationTest extends TestCase
{
    public function test_api_key_creation_hashes_secret_and_scopes_work(): void
    {
        $org = Organization::first();
        $user = User::first();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Organization-Id' => (string) $org->id,
        ])->postJson('/api/v1/api-keys', [
            'name' => 'WMS Warehouse Integration Key',
            'scopes' => ['read:offers', 'write:deals'],
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'WMS Warehouse Integration Key',
                    'scopes' => ['read:offers', 'write:deals'],
                ],
            ]);

        $this->assertNotNull($response->json('data.raw_api_key'));

        $apiKeyRecord = ApiKey::where('name', 'WMS Warehouse Integration Key')->first();
        $this->assertNotNull($apiKeyRecord);
        $this->assertNotEquals($response->json('data.raw_api_key'), $apiKeyRecord->key_hash);
    }

    public function test_webhook_hmac_signature_generation(): void
    {
        $dispatcher = app(WebhookDispatcherService::class);
        $secret = 'whsec_enterprise_secret_key_123';
        $payload = ['event' => 'deal.created', 'deal_id' => 999];

        $signature = $dispatcher->computeSignature($payload, $secret);

        $this->assertIsString($signature);
        $this->assertEquals(64, strlen($signature)); // SHA-256 hex string length
    }
}
