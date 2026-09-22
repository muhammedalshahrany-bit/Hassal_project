<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * The two records a person owns: their own account, and their own depot.
 *
 * Both are surfaces that used to be write-once in this product — you could open
 * an account and open a warehouse, and then correct neither. That is not a
 * missing convenience, it is a data-quality claim: a phone number that cannot be
 * fixed is a contact route that quietly dies, and a map pin captured wrongly by
 * a browser is a distance the "nearest offers" list then gets wrong for everyone.
 *
 * What these tests hold the endpoints to: a profile edit touches the person, not
 * the company; the login address is not editable from a form; changing a password
 * closes the sessions the old one opened; and a warehouse edit stays inside the
 * organization that owns the row.
 */
class ProfileAndWarehouseEditTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    // ── The person ──────────────────────────────────────────────────────────

    public function test_a_person_can_correct_their_own_details(): void
    {
        $user = $this->user('سارة');

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/profile', [
            'name'        => 'سارة عبدالله',
            'phone'       => '+967771234567',
            'national_id' => '01-B-447712',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'سارة عبدالله')
            ->assertJsonPath('data.phone', '+967771234567');

        $fresh = $user->fresh();
        $this->assertSame('سارة عبدالله', $fresh->name);
        $this->assertSame('+967771234567', $fresh->phone);
        $this->assertSame('01-B-447712', $fresh->national_id);
    }

    public function test_the_login_address_is_not_a_profile_field(): void
    {
        $user = $this->user('أمين');
        $email = $user->email;

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/profile', [
            'name'  => 'أمين الحربي',
            'email' => 'someone.else@example.test',
        ])->assertOk();

        // Moving an address is a verified operation, not a form edit: whoever
        // controls the mailbox controls the password resets.
        $this->assertSame($email, $user->fresh()->email);
    }

    public function test_a_phone_number_that_already_verifies_someone_else_is_refused(): void
    {
        $mine = $this->user('لي');
        $theirs = $this->user('غيري');
        $theirs->update(['phone' => '+967770000000']);

        // What this account already holds, so the assertion below can say the
        // refused write changed nothing. A fixture account carries a phone from
        // the moment it exists — see `HassalFixtures::user()`.
        $kept = $mine->phone;
        $this->assertNotNull($kept);
        $this->assertNotSame($kept, $theirs->phone);

        Sanctum::actingAs($mine);

        $this->patchJson('/api/v1/auth/profile', ['phone' => '+967770000000'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['phone']]]);

        $this->assertSame($kept, $mine->fresh()->phone, 'A refused write left its mark on the column anyway.');
    }

    public function test_changing_a_password_closes_the_sessions_the_old_one_opened(): void
    {
        $user = $this->user('ريم');
        $old = $this->passwordFor($user);

        $laptop = $user->createToken('laptop');
        $stolen = $user->createToken('somewhere else');

        $this->withHeader('Authorization', 'Bearer ' . $laptop->plainTextToken)
            ->postJson('/api/v1/auth/password', [
                'current_password' => 'not-the-password',
                'password'         => 'NewPass-2026!',
                'password_confirmation' => 'NewPass-2026!',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CURRENT_PASSWORD_INCORRECT');

        // Two tokens, still two tokens: a wrong guess must not log anyone out.
        $this->assertSame(2, $user->tokens()->count());

        $this->withHeader('Authorization', 'Bearer ' . $laptop->plainTextToken)
            ->postJson('/api/v1/auth/password', [
                'current_password'    => $old,
                'password'            => 'NewPass-2026!',
                'password_confirmation' => 'NewPass-2026!',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('NewPass-2026!', $user->fresh()->password));
        $this->assertFalse(Hash::check($old, $user->fresh()->password));

        // The device that made the change stays signed in; everything the old
        // password opened is gone.
        $this->assertTrue($user->tokens()->where('name', 'laptop')->exists());
        $this->assertFalse($user->tokens()->where('name', 'somewhere else')->exists());
        $this->assertSame(
            1,
            DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count()
        );
    }

    public function test_a_password_change_needs_the_current_one_rather_than_mere_knowledge_of_a_token(): void
    {
        $user = $this->user('نايف');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/password', [
            'password'            => 'Another-Pass-9',
            'password_confirmation' => 'Another-Pass-9',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['current_password']]]);

        // A token in a stolen browser tab is not enough to take the account over
        // quietly: the replacement has to be justified by the secret itself.
        $this->assertNotSame('Another-Pass-9', $user->fresh()->password);
    }

    // ── The depot ───────────────────────────────────────────────────────────

    public function test_the_owner_of_a_warehouse_can_move_its_pin(): void
    {
        [$user, $org] = $this->accountWithOrganization('SellerAdmin', 'seller', 'شركة التوزيع');
        $warehouse = $this->warehouse($org);

        Sanctum::actingAs($user);
        $headers = $this->withTenant($org);

        $this->withHeaders($headers)->putJson("/api/v1/warehouses/{$warehouse->id}", [
            'name'         => 'مستودع المنصورة',
            'city'         => self::CITY,
            'address_line' => 'شارع التسعين، المنصورة',
            'latitude'     => 12.7900000,
            'longitude'    => 45.0100000,
        ])->assertOk()->assertJsonPath('data.name', 'مستودع المنصورة');

        $fresh = $warehouse->fresh();
        $this->assertSame('12.7900000', (string) $fresh->latitude);
        $this->assertSame($org->id, $fresh->organization_id);
    }

    public function test_a_reading_outside_the_world_is_refused_rather_than_stored(): void
    {
        [$user, $org] = $this->accountWithOrganization('SellerAdmin', 'seller', 'شركة التوزيع');
        $warehouse = $this->warehouse($org);

        Sanctum::actingAs($user);
        $headers = $this->withTenant($org);

        // A browser geolocation failure looks exactly like this, and a nonsense
        // pin is worse than a refused one: it silently corrupts every distance
        // the discovery feature computes from it.
        foreach ([['latitude' => 999, 'longitude' => self::LNG], ['latitude' => self::LAT, 'longitude' => -200]] as $bad) {
            $this->withHeaders($headers)->postJson('/api/v1/warehouses', array_merge([
                'name'         => 'مستودع خاطئ',
                'city'         => self::CITY,
                'address_line' => 'شارع ما',
            ], $bad))->assertStatus(422);
        }

        $this->assertSame(1, Warehouse::query()->where('organization_id', $org->id)->count());
    }

    public function test_a_business_cannot_edit_a_depot_it_does_not_own(): void
    {
        [$victim, $victimOrg] = $this->accountWithOrganization('SellerAdmin', 'seller', 'شركة أ');
        $warehouse = $this->warehouse($victimOrg);

        [$attacker, $attackerOrg] = $this->accountWithOrganization('SellerAdmin', 'seller', 'شركة ب');

        Sanctum::actingAs($attacker);

        $this->withHeaders($this->withTenant($attackerOrg))
            ->putJson("/api/v1/warehouses/{$warehouse->id}", [
                'name'         => 'مستودع مسروق',
                'city'         => self::CITY,
                'address_line' => 'شارع ما',
                'latitude'     => self::LAT,
                'longitude'    => self::LNG,
            ])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'WAREHOUSE_NOT_FOUND');

        $this->assertNotSame('مستودع مسروق', $warehouse->fresh()->name);
    }
}
