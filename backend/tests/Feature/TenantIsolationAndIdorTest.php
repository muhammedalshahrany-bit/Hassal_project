<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantIsolationAndIdorTest extends TestCase
{
    protected User $userOrgA;
    protected User $userOrgB;
    protected Organization $orgA;
    protected Organization $orgB;
    protected Offer $offerOrgA;
    protected Offer $offerOrgB;

    protected function setUp(): void
    {
        parent::setUp();

        // Create Org A (Seller A)
        $this->orgA = Organization::firstOrCreate(
            ['commercial_registration_number' => '1010111111'],
            [
                'name_ar' => 'شركة توريد أ',
                'name_en' => 'Supplier Org A',
                'type' => 'seller',
                'status' => 'active',
                'kyc_status' => 'verified',
            ]
        );

        $this->userOrgA = User::firstOrCreate(
            ['email' => 'admin@orga.sa'],
            [
                'name' => 'Manager Org A',
                'phone' => '+966501111111',
                'password' => Hash::make('Secret123!'),
                'is_active' => true,
            ]
        );

        OrganizationMembership::firstOrCreate(
            ['organization_id' => $this->orgA->id, 'user_id' => $this->userOrgA->id],
            ['role' => 'admin', 'is_active' => true]
        );

        // Create Org B (Seller B)
        $this->orgB = Organization::firstOrCreate(
            ['commercial_registration_number' => '1010222222'],
            [
                'name_ar' => 'شركة توريد ب',
                'name_en' => 'Supplier Org B',
                'type' => 'seller',
                'status' => 'active',
                'kyc_status' => 'verified',
            ]
        );

        $this->userOrgB = User::firstOrCreate(
            ['email' => 'admin@orgb.sa'],
            [
                'name' => 'Manager Org B',
                'phone' => '+966502222222',
                'password' => Hash::make('Secret123!'),
                'is_active' => true,
            ]
        );

        OrganizationMembership::firstOrCreate(
            ['organization_id' => $this->orgB->id, 'user_id' => $this->userOrgB->id],
            ['role' => 'admin', 'is_active' => true]
        );
    }

    public function test_user_cannot_switch_to_organization_without_membership(): void
    {
        $token = $this->userOrgA->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Organization-Id' => (string) $this->orgB->id,
        ])->getJson('/api/v1/auth/me');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error_code' => 'ORGANIZATION_ACCESS_DENIED',
            ]);
    }

    public function test_user_cannot_update_offer_belonging_to_another_organization(): void
    {
        // Find or create an offer owned by Org B
        $offerB = Offer::where('seller_organization_id', $this->orgB->id)->first();
        if (!$offerB) {
            $offerB = Offer::create([
                'seller_organization_id' => $this->orgB->id,
                'warehouse_id' => 1,
                'product_variant_id' => 1,
                'category_id' => null,
                'title_ar' => 'عرض خاص بمؤسسة ب',
                'title_en' => 'Org B Exclusive Offer',
                'opportunity_reason' => 'SURPLUS_STOCK',
                'status' => Offer::STATUS_ACTIVE,
                'min_order_quantity' => 1,
                'total_quantity' => 500,
                'available_quantity' => 500,
                'reserved_quantity' => 0,
                'sold_quantity' => 0,
                'unit' => 'carton',
                'unit_price' => 75.00,
                'currency_code' => 'SAR',
            ]);
        }

        $tokenA = $this->userOrgA->createToken('test')->plainTextToken;

        // User A attempts to pause/update Org B's offer
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $tokenA,
            'X-Organization-Id' => (string) $this->orgA->id,
        ])->putJson("/api/v1/marketplace/offers/{$offerB->id}/status", [
            'status' => 'paused',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error_code' => 'UNAUTHORIZED_OFFER_ACTION',
            ]);
    }

    public function test_user_cannot_access_foreign_deal(): void
    {
        // Create Deal between Org B (Seller) and a third-party buyer
        $warehouseId = Warehouse::query()->value('id') ?? 1;

        $deal = Deal::firstOrCreate(
            ['deal_number' => 'DEAL-ISOLATION-001'],
            [
                'seller_organization_id' => $this->orgB->id,
                'buyer_organization_id' => $this->orgB->id,
                'seller_user_id' => $this->userOrgB->id,
                'buyer_user_id' => $this->userOrgB->id,
                'offer_id' => 1,
                'warehouse_id' => $warehouseId,
                'status' => Deal::STATUS_ACCEPTED,
                'quantity' => 10,
                'unit_price' => 50,
                'base_amount' => 500,
                'platform_fee_amount' => 12.5,
                'buyer_total_amount' => 575,
                'seller_net_amount' => 562.5,
                'currency_code' => 'SAR',
            ]
        );

        $tokenA = $this->userOrgA->createToken('test')->plainTextToken;

        // User A tries to view Deal
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $tokenA,
            'X-Organization-Id' => (string) $this->orgA->id,
        ])->getJson("/api/v1/deals/{$deal->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error_code' => 'DEAL_ACCESS_DENIED',
            ]);
    }

    public function test_buyer_can_create_demand_with_legacy_payload_fields(): void
    {
        $token = $this->userOrgA->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Organization-Id' => (string) $this->orgA->id,
        ])->postJson('/api/v1/demands', [
            'title' => 'مطلوب توريد 500 كرتون أرز',
            'description' => 'إكراماً للتوريد حسب المواصفات الغذائية',
            'quantity' => 500,
            'target_unit_price' => 18.5,
            'target_city' => 'الرياض',
            'required_by_date' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('demands', [
            'buyer_organization_id' => $this->orgA->id,
            'title_ar' => 'مطلوب توريد 500 كرتون أرز',
            'delivery_city' => 'الرياض',
            'target_quantity' => 500,
        ]);
    }
}
