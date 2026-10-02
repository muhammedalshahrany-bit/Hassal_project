<?php

namespace Tests\Feature\Auth;

use App\Auth\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * What a device row is for: naming a session so it can be revoked.
 *
 * The mobile client unlocks its own stored token with a fingerprint, and the
 * platform's answer to that is deliberately modest — it records the claim, shows
 * it in the account, and lets the person delete the device together with the
 * bearer credential it holds. There is no biometric authentication path on the
 * server, and the second-to-last test is the one that keeps that true: a device
 * key plus a biometric flag must never open a session by itself.
 */
class SignInDevicesTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /**
     * @param  array<string, mixed>  $device
     * @return array<int, array<string, mixed>>
     */
    private function devices(string $token): array
    {
        $data = $this->withToken($token)->getJson('/api/v1/auth/devices')->assertOk()->json('data');

        return is_array($data) ? $data : [];
    }

    public function test_a_signin_that_names_its_device_lists_it_and_points_at_the_session(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user, [
            'device_key' => 'mob-1',
            'device_name' => 'هاتف تجريبي',
            'device_platform' => 'android',
            'biometric_unlock' => true,
        ]);

        $devices = $this->devices($token);

        $this->assertCount(1, $devices);
        $this->assertSame('هاتف تجريبي', $devices[0]['name']);
        $this->assertSame('android', $devices[0]['platform']);
        $this->assertTrue($devices[0]['biometric_unlock']);
        // "This is the device asking" is computed per request, and the account
        // screen needs it before offering a revoke that would sign the person
        // out of the session they are reading the list from.
        $this->assertTrue($devices[0]['current']);

        $device = UserDevice::firstWhere('device_key', 'mob-1');
        $this->assertSame($user->id, $device->user_id);
        $this->assertNotNull($device->token_id, 'The row must name the token it can revoke.');
    }

    public function test_a_signin_that_names_no_device_leaves_the_list_alone(): void
    {
        $user = $this->user();

        // The web client and every integration written before this field exists
        // send no key; they must keep working and must not be invented into
        // somebody's device list.
        $this->assertSame([], $this->devices($this->tokenFor($user)));
        $this->assertSame(0, UserDevice::where('user_id', $user->id)->count());
    }

    public function test_signing_in_again_from_the_same_device_repoints_it_instead_of_duplicating_it(): void
    {
        $user = $this->user();
        $this->tokenFor($user, ['device_key' => 'mob-1', 'device_platform' => 'android']);
        $before = UserDevice::firstWhere('device_key', 'mob-1')->token_id;

        $second = $this->tokenFor($user, ['device_key' => 'mob-1', 'device_platform' => 'android']);

        $row = UserDevice::firstWhere('device_key', 'mob-1');
        $this->assertSame(1, UserDevice::where('device_key', 'mob-1')->count());
        $this->assertNotSame($before, $row->token_id, 'A new session mints a new token; the row follows it.');
        $this->assertCount(1, $this->devices($second));
    }

    public function test_revoking_a_device_takes_the_session_it_held_with_it(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user, ['device_key' => 'mob-1', 'device_platform' => 'android']);
        $id = UserDevice::firstWhere('device_key', 'mob-1')->id;

        $this->withToken($token)->deleteJson("/api/v1/auth/devices/{$id}")->assertOk();

        // One container serves all the requests in a test, so the guard would
        // otherwise answer from the session it built during the revoke call.
        $this->asFresh();

        $this->assertDatabaseMissing('user_devices', ['id' => $id]);
        // The whole point of storing a token id: taking the phone off the
        // account must not leave the phone holding a working credential.
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_one_account_cannot_read_or_revoke_another_accounts_device(): void
    {
        $owner = $this->user();
        $intruder = $this->user();

        $ownerToken = $this->tokenFor($owner, ['device_key' => 'mob-1', 'device_name' => 'هاتف المالك', 'device_platform' => 'android']);
        $intruderToken = $this->tokenFor($intruder, ['device_key' => 'mob-2', 'device_name' => 'هاتف آخر', 'device_platform' => 'ios']);

        $id = UserDevice::firstWhere('device_key', 'mob-1')->id;

        $seen = $this->devices($intruderToken);
        $this->assertCount(1, $seen);
        $this->assertSame('هاتف آخر', $seen[0]['name']);

        $this->withToken($intruderToken)->patchJson("/api/v1/auth/devices/{$id}", ['name' => 'ليس هاتفك'])->assertNotFound();
        $this->withToken($intruderToken)->deleteJson("/api/v1/auth/devices/{$id}")->assertNotFound();

        $this->assertDatabaseHas('user_devices', ['id' => $id, 'name' => 'هاتف المالك']);
        $this->asFresh();
        $this->withToken($ownerToken)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_a_device_can_report_its_own_unlock_setting_after_signin(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user, ['device_key' => 'mob-1', 'device_platform' => 'android']);

        $response = $this->withToken($token)->postJson('/api/v1/auth/devices', [
            'device_key' => 'mob-1',
            'device_name' => 'هاتف تجريبي',
            'device_platform' => 'android',
            'biometric_unlock' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.biometric_unlock', true)
            ->assertJsonPath('data.current', true);

        // The row keeps pointing at the token that made the call, so the account
        // can still name the session this device holds.
        $this->assertNotNull(UserDevice::firstWhere('device_key', 'mob-1')->token_id);
    }

    public function test_a_fingerprint_claim_never_authenticates_anything(): void
    {
        $user = $this->user();
        $this->tokenFor($user, ['device_key' => 'mob-1', 'device_platform' => 'android', 'biometric_unlock' => true]);

        // No token, no session: a device key and a biometric flag describe a
        // device, and nothing in this platform accepts them as proof of who is
        // holding it. The biometric unlocks the stored token on the handset, and
        // the credential that reaches this server afterwards is the same Sanctum
        // token a typed password would have produced.
        $this->postJson('/api/v1/auth/devices', [
            'device_key' => 'mob-1',
            'device_platform' => 'android',
            'biometric_unlock' => true,
        ])->assertUnauthorized();

        $this->getJson('/api/v1/auth/devices')->assertUnauthorized();
    }

    public function test_a_device_whose_token_died_stops_claiming_a_live_session(): void
    {
        $user = $this->user();
        $oldPhone = $this->tokenFor($user, ['device_key' => 'mob-a', 'device_name' => 'الهاتف القديم', 'device_platform' => 'android']);
        $newPhone = $this->tokenFor($user, ['device_key' => 'mob-b', 'device_name' => 'الهاتف الجديد', 'device_platform' => 'ios']);

        // The usual way a token dies while its device row survives: a password
        // change revokes every other session on the account.
        $this->withToken($newPhone)->postJson('/api/v1/auth/password', [
            'current_password' => $this->passwordFor($user),
            'password' => 'a-different-long-password',
            'password_confirmation' => 'a-different-long-password',
        ])->assertOk();

        // The old session is gone; ask as a fresh guard so the answer comes from
        // the token rather than from the user object the previous call resolved.
        $this->asFresh();
        $this->withToken($oldPhone)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->devices($newPhone);

        $this->assertNull(UserDevice::firstWhere('device_key', 'mob-a')->token_id, 'A dead token must not be pointed at.');
        $this->assertNotNull(UserDevice::firstWhere('device_key', 'mob-b')->token_id);
    }
}
