<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_health_check_returns_healthy_status(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'healthy',
                    'platform' => 'HASSAL B2B Marketplace API',
                ],
            ]);
    }

    public function test_user_can_register_with_organization(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'محمد السبيعي',
            'email' => 'mohammed@testsupply.sa',
            'phone' => '+966551234567',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'organization_name' => 'مؤسسة التوريد الحديثة',
            'organization_type' => 'seller',
            'commercial_registration_number' => '1010991122',
            'vat_number' => '300991122330003',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'token',
                    'organization' => ['id', 'name_ar', 'type'],
                ],
            ]);

        $this->assertDatabaseHas('users', ['email' => 'mohammed@testsupply.sa']);
        $this->assertDatabaseHas('organizations', ['commercial_registration_number' => '1010991122']);
    }

    public function test_user_can_login_with_seeded_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'seller@alwataniya.sa',
            'password' => 'Hassal@2026!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'user' => ['id', 'name', 'email', 'roles'],
                    'token',
                    'active_organization',
                ],
            ]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'seller@alwataniya.sa',
            'password' => 'WrongPassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                ],
            ]);
    }

    public function test_authenticated_user_can_access_me_profile(): void
    {
        $user = User::where('email', 'buyer@riyadhfood.sa')->first();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'email' => 'buyer@riyadhfood.sa',
                    ],
                ],
            ]);
    }
}
