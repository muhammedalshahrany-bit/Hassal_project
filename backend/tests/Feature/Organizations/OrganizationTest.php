<?php

namespace Tests\Feature\Organizations;

use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * A business on HASSAL is a legal-ish record, not a display name.
 *
 * §8 requires the commercial registration to be a real field the platform can
 * act on, and §10 says verification is a state the platform reaches through a
 * process — never a label it applies because somebody typed a number. These
 * tests hold both rules in place, including the boring one that matters most:
 * one business cannot read another's records.
 */
class OrganizationTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    public function test_a_business_can_be_registered_with_its_legal_identity(): void
    {
        [$user, ] = $this->accountWithOrganization('BuyerAdmin', 'buyer');
        Sanctum::actingAs($user);

        $cr = 'CR-ORG-' . strtoupper(substr(uniqid(), -8));
        $vat = 'VAT-ORG-' . strtoupper(substr(uniqid(), -8));

        $response = $this->postJson('/api/v1/organizations', [
            'name_ar' => 'مؤسسة النور للمواد الغذائية',
            'name_en' => 'Al Noor Food Trading',
            'type' => 'seller',
            'commercial_registration_number' => $cr,
            'vat_number' => $vat,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'seller');

        $organization = Organization::where('commercial_registration_number', $cr)->firstOrFail();

        // Registration is recorded, not believed: the status says the platform
        // still has to verify it.
        $this->assertSame(Organization::STATUS_PENDING, $organization->status);
        $this->assertNull($organization->kyc_verified_at);

        // The founder owns it.
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
    }

    /**
     * Whether a registration number is demanded is the operator's call, made
     * against the law of the country this deployment serves (§11). What is not
     * the code's call is what to write when the answer is "not required": the
     * record must say it is missing, not carry a number nobody issued.
     */
    public function test_a_required_registration_number_is_still_required(): void
    {
        [$user, ] = $this->accountWithOrganization('BuyerAdmin', 'buyer');
        Sanctum::actingAs($user);

        $this->setSetting('legal.require_commercial_registration', '1', 'boolean');

        $this->postJson('/api/v1/organizations', [
            'name_ar' => 'منشأة بلا سجل',
            'name_en' => 'No Registry Business',
            'type' => 'seller',
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['commercial_registration_number']]]);
    }

    public function test_a_business_can_register_without_one_when_the_operator_does_not_require_it(): void
    {
        [$user, ] = $this->accountWithOrganization('BuyerAdmin', 'buyer');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/organizations', [
            'name_ar' => 'بقالة الحي',
            'name_en' => 'Neighbourhood Grocery',
            'type' => 'seller',
        ]);

        $response->assertCreated()
            // Null, not "PENDING-something". A placeholder reads as an
            // identifier to the next human who sees it.
            ->assertJsonPath('data.commercial_registration_number', null)
            ->assertJsonPath('data.status', Organization::STATUS_PENDING);

        $this->assertNull(
            Organization::where('name_en', 'Neighbourhood Grocery')->value('commercial_registration_number')
        );
    }

    public function test_an_applicant_cannot_register_itself_as_a_platform_administrator(): void
    {
        [$user, ] = $this->accountWithOrganization('BuyerAdmin', 'buyer');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/organizations', [
            'name_ar' => 'جهة إشرافية',
            'name_en' => 'Self Appointed Admin',
            'type' => Organization::TYPE_ADMIN,
        ]);

        $this->assertApiValidationErrors($response, ['type']);

        $this->assertDatabaseMissing('organizations', ['name_en' => 'Self Appointed Admin']);
    }

    public function test_a_super_administrator_can_register_a_platform_organization(): void
    {
        $admin = $this->user('مشرفة المنصة');
        $admin->assignRole('SuperAdmin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/organizations', [
            'name_ar' => 'وحدة الإشراف',
            'name_en' => 'Platform Oversight',
            'type' => Organization::TYPE_ADMIN,
        ])->assertCreated()->assertJsonPath('data.type', Organization::TYPE_ADMIN);
    }

    public function test_two_businesses_cannot_share_one_registration_number(): void
    {
        [$user, $organization] = $this->accountWithOrganization('BuyerAdmin', 'buyer');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/organizations', [
            'name_ar' => 'منشأة ثانية',
            'name_en' => 'Second Business',
            'type' => 'seller',
            'commercial_registration_number' => $organization->commercial_registration_number,
            'vat_number' => 'VAT-CLONE-' . strtoupper(substr(uniqid(), -6)),
        ])->assertStatus(422);
    }

    public function test_an_owner_can_add_a_staff_member_and_change_their_internal_role(): void
    {
        [$owner, $organization] = $this->accountWithOrganization('SellerAdmin', 'seller');
        $staff = $this->user('موظف المخزون');
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/organizations/' . $organization->id . '/members', [
            'user_id' => $staff->id,
            'role' => 'staff',
            'reason' => 'تعيين مسؤول مخزون',
        ], $this->withTenant($organization))->assertCreated();

        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $staff->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->putJson('/api/v1/organizations/' . $organization->id . '/members/' . $staff->id, [
            'role' => 'finance',
            'reason' => 'نقل مسؤولية الدفعات',
        ], $this->withTenant($organization))->assertOk()
            ->assertJsonPath('data.role', 'finance');

        // Every staffing write leaves a trace naming who decided, from what to
        // what, and the reason they gave.
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'action' => 'ORG_MEMBER_ROLE_CHANGED',
        ]);
    }

    public function test_a_stranger_cannot_manage_another_business_staff(): void
    {
        [, $organization] = $this->accountWithOrganization('SellerAdmin', 'seller');
        [$intruder, $intruderOrg] = $this->accountWithOrganization('BuyerAdmin', 'buyer');
        $victim = $organization->memberships()->first()->user;

        Sanctum::actingAs($intruder);

        $this->postJson('/api/v1/organizations/' . $organization->id . '/members', [
            'user_id' => $victim->id,
            'role' => 'admin',
            'reason' => 'تجربة وصول من خارج المنشأة',
        ], $this->withTenant($intruderOrg))->assertForbidden();

        $this->putJson('/api/v1/organizations/' . $organization->id . '/members/' . $victim->id, [
            'role' => 'staff',
            'reason' => 'تجربة وصول من خارج المنشأة',
        ], $this->withTenant($intruderOrg))->assertForbidden();

        // No membership in the victim's business was created for the intruder.
        $this->assertDatabaseMissing('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $intruder->id,
        ]);
    }

    public function test_the_registration_number_and_member_roster_are_not_public_read(): void
    {
        [, $organization] = $this->accountWithOrganization('SellerAdmin', 'seller');
        [$outsider, $outsiderOrg] = $this->accountWithOrganization('BuyerAdmin', 'buyer');

        Sanctum::actingAs($outsider);

        // Any authenticated account could previously enumerate organization ids
        // and collect every business's commercial registration, VAT number and
        // staff contact details.
        $this->getJson('/api/v1/organizations/' . $organization->id, $this->withTenant($outsiderOrg))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ORGANIZATION_ACCESS_DENIED');

        $this->getJson('/api/v1/organizations/' . $organization->id . '/members', $this->withTenant($outsiderOrg))
            ->assertForbidden();
    }

    public function test_a_member_can_read_their_own_business_profile(): void
    {
        [$user, $organization] = $this->accountWithOrganization('BuyerAdmin', 'buyer');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/organizations/' . $organization->id, $this->withTenant($organization))
            ->assertOk()
            ->assertJsonPath('data.id', $organization->id)
            ->assertJsonPath('data.commercial_registration_number', $organization->commercial_registration_number);
    }

    public function test_the_last_owner_cannot_be_locked_out_of_a_business(): void
    {
        [$owner, $organization] = $this->accountWithOrganization('SellerAdmin', 'seller');
        Sanctum::actingAs($owner);

        $this->deleteJson(
            '/api/v1/organizations/' . $organization->id . '/members/' . $owner->id,
            ['reason' => 'محاولة إغلاق المنشأة على نفسها'],
            $this->withTenant($organization)
        )
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CANNOT_REMOVE_LAST_OWNER');

        $this->assertSame(1, OrganizationMembership::where('organization_id', $organization->id)
            ->where('is_active', true)
            ->count());
    }

    public function test_a_business_can_complete_its_own_profile_record(): void
    {
        [$user, $organization] = $this->accountWithOrganization('SellerAdmin', 'seller');
        Sanctum::actingAs($user);

        // These columns existed and nothing could fill them: registration takes
        // a name, a type and the legal numbers, and the update endpoint accepted
        // the same three, so a business had no way to record where it is or how
        // a counterparty reaches it.
        $this->putJson('/api/v1/organizations/' . $organization->id, [
            'trade_name_ar' => 'بقالة الحي',
            'city' => 'عدن',
            'country_code' => 'YE',
            'phone' => '+967 2 000 000',
            'email' => 'store@example.test',
        ], $this->withTenant($organization))->assertOk();

        $this->getJson('/api/v1/organizations/' . $organization->id, $this->withTenant($organization))
            ->assertOk()
            ->assertJsonPath('data.trade_name_ar', 'بقالة الحي')
            ->assertJsonPath('data.city', 'عدن')
            ->assertJsonPath('data.country_code', 'YE');
    }

    public function test_changing_a_legal_identifier_reopens_the_platform_review(): void
    {
        [$user, $organization] = $this->accountWithOrganization('SellerAdmin', 'seller', null, [
            'kyc_verified_at' => now()->subDays(3),
        ]);
        Sanctum::actingAs($user);

        $this->assertSame(Organization::STATUS_VERIFIED, $organization->status);

        $this->putJson('/api/v1/organizations/' . $organization->id, [
            'commercial_registration_number' => 'CR-NEW-' . strtoupper(substr(uniqid(), -6)),
        ], $this->withTenant($organization))
            ->assertOk()
            ->assertJsonPath('data.status', Organization::STATUS_PENDING)
            ->assertJsonPath('data.kyc_verified_at', null);
    }

    public function test_saving_the_profile_without_touching_the_identifiers_keeps_the_decision(): void
    {
        [$user, $organization] = $this->accountWithOrganization('SellerAdmin', 'seller', null, [
            'kyc_verified_at' => now()->subDays(3),
        ]);
        Sanctum::actingAs($user);

        // The form echoes the numbers it was shown. That is not a new claim, and
        // reopening the review on every save would make verification unusable.
        $this->putJson('/api/v1/organizations/' . $organization->id, [
            'city' => 'عدن',
            'commercial_registration_number' => $organization->commercial_registration_number,
            'vat_number' => $organization->vat_number,
        ], $this->withTenant($organization))
            ->assertOk()
            ->assertJsonPath('data.status', Organization::STATUS_VERIFIED);
    }
}
