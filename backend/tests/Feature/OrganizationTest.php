<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    public function test_authenticated_user_can_create_new_organization(): void
    {
        $user = User::where('email', 'buyer@riyadhfood.sa')->first();
        $token = $user->createToken('test')->plainTextToken;

        $crNumber = '1010' . rand(100000, 999999);
        $vatNumber = '300' . rand(100000000000, 999999999999);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v1/organizations', [
            'name_ar' => 'شركة فروع التموين الغذائية',
            'name_en' => 'Branches Catering Co.',
            'type' => 'buyer',
            'commercial_registration_number' => $crNumber,
            'vat_number' => $vatNumber,
            'credit_limit' => 150000.00,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name_ar' => 'شركة فروع التموين الغذائية',
                    'commercial_registration_number' => $crNumber,
                ],
            ]);
    }

    public function test_idempotency_key_prevents_duplicate_submission(): void
    {
        $user = User::where('email', 'seller@alwataniya.sa')->first();
        $token = $user->createToken('test')->plainTextToken;
        $idempotencyKey = (string) Str::uuid();

        $crNumber = '1010' . rand(100000, 999999);
        $vatNumber = '300' . rand(100000000000, 999999999999);

        $payload = [
            'name_ar' => 'مصنع تمور القصيم النموذجي',
            'name_en' => 'Qassim Dates Factory',
            'type' => 'seller',
            'commercial_registration_number' => $crNumber,
            'vat_number' => $vatNumber,
        ];

        // First request
        $firstResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/organizations', $payload);

        $firstResponse->assertStatus(201);
        $firstOrgId = $firstResponse->json('data.id');

        // Replay same request with same idempotency key
        $secondResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/organizations', $payload);

        $secondResponse->assertStatus(201);
        $secondResponse->assertHeader('X-Cache-Lookup', 'HIT');
        $this->assertEquals($firstOrgId, $secondResponse->json('data.id'));
    }

    public function test_tenant_context_resolution_via_header(): void
    {
        $user = User::where('email', 'seller@alwataniya.sa')->first();
        $org = Organization::where('commercial_registration_number', '1010887766')->first();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Organization-Id' => (string) $org->id,
        ])->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'current_organization' => [
                        'id' => $org->id,
                        'name_en' => 'National Food Supply Co.',
                    ],
                ],
            ]);
    }
}
