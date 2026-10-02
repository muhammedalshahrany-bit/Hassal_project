<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\DB;

/**
 * The account's list of places it is signed in from.
 *
 * Two operations live here because both are read by the sign-in path and by the
 * device endpoints, and a device list that the sign-in does not maintain is a
 * list that goes stale on the first re-login.
 */
class DeviceRegistry
{
    /**
     * Note the device a session came from, pointing it at the token that session
     * was issued under.
     *
     * Returns null when the caller named no device: the web client and every
     * existing integration send none, and they must keep working exactly as
     * before rather than appearing in a list nobody asked them to fill.
     *
     * @param  array{key: string, name: string, platform: string, biometric_unlock: bool}|null  $device
     */
    public function record(User $user, ?array $device, ?int $tokenId): ?UserDevice
    {
        if ($device === null) {
            return null;
        }

        $row = UserDevice::firstOrNew([
            'user_id' => $user->id,
            'device_key' => $device['key'],
        ]);

        $row->fill([
            'name' => $device['name'],
            'platform' => $device['platform'],
            'biometric_unlock' => $device['biometric_unlock'],
            // A sign-in always mints a new token, so the pointer moves rather
            // than accumulating: one device, one live session, one row the
            // account can revoke.
            'token_id' => $tokenId,
            'last_seen_at' => now(),
        ])->save();

        return $row;
    }

    /**
     * Drop pointers to tokens that no longer exist.
     *
     * A token can leave without its device leaving: expiry, a password change
     * that revokes the others, a logout that deletes only itself. A device row
     * still naming one of those would offer a "revoke" that deletes nothing, and
     * the account screen would list a session as live when it is already dead —
     * which is worse than an empty list, because it is a reassurance that is
     * false.
     *
     * Called on read rather than from each writer: the writers that end a token
     * are spread across this controller and would each have to remember this,
     * and the rows are cheap to correct at the moment anybody looks at them.
     */
    public function pruneStaleTokens(User $user): void
    {
        $live = $user->tokens()->pluck('id')->all();

        UserDevice::where('user_id', $user->id)
            ->whereNotNull('token_id')
            ->when(
                $live === [],
                fn ($query) => $query->whereNotNull('token_id'),
                fn ($query) => $query->whereNotIn('token_id', $live)
            )
            ->update(['token_id' => null]);
    }

    /**
     * Delete the bearer credential a device row was holding, if it still exists.
     *
     * This is the reason the row stores a token id at all: a person who removes
     * their old phone from the account should not have to hunt for the token
     * that phone can still sign requests with.
     */
    public function revokeToken(User $user, ?int $tokenId): void
    {
        if ($tokenId === null) {
            return;
        }

        DB::table('personal_access_tokens')
            ->where('id', $tokenId)
            ->where('tokenable_id', $user->id)
            ->where('tokenable_type', $user::class)
            ->delete();
    }
}
