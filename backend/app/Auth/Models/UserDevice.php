<?php

namespace App\Auth\Models;

use App\Identity\Models\User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One device a person signs in from, recorded so the account can list it.
 *
 * The row exists because a bearer token says nothing about the machine holding
 * it: until now the platform could not answer "which of my phones is still
 * signed in?", and a person who replaced a handset had no way to drop the
 * session the old one still carried.
 *
 * `device_key` is a client-generated label, not a secret. It identifies a
 * device only inside one account (the unique index is `(user_id, device_key)`),
 * and every read filters on `user_id` from the token, so a guessed key from
 * another account reaches nothing.
 *
 * `biometric_unlock` records what the device claims about its own lock screen.
 * It is a label the account shows and revokes, never an authentication path:
 * this server has no biometric endpoint, and the credential that unlocked the
 * door was the same Sanctum token a password login would have produced.
 */
class UserDevice extends Model
{
    /**
     * Platforms a client may name itself with.
     *
     * The migration has no CHECK constraint on the column, so the set lives here
     * and the request validates against it — a stray string would otherwise be
     * stored and then rendered as an unknown icon in the account screen.
     */
    public const PLATFORMS = ['android', 'ios', 'web'];

    protected $fillable = [
        'user_id',
        'device_key',
        'name',
        'platform',
        'token_id',
        'biometric_unlock',
        'last_seen_at',
    ];

    protected $casts = [
        'biometric_unlock' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What the account screen is shown for one device.
     *
     * `current` is computed rather than stored: it is the answer to "is this the
     * session asking?", which only the acting request knows, and it is what lets
     * the interface warn that revoking this row signs this device out too.
     *
     * @return array<string, mixed>
     */
    public function toAccountView(bool $current): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'platform' => $this->platform,
            'biometric_unlock' => $this->biometric_unlock,
            'current' => $current,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
