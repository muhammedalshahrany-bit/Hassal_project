<?php

namespace App\Auth\Models;

use App\Identity\Models\User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account at one outside provider, attached to one HASSAL account.
 *
 * The row exists so a repeat sign-in can be recognised by what is stable —
 * the provider's subject number — rather than by an email address that the
 * provider may hand to somebody else after it is released.
 *
 * It holds no credential. An ID token is a bearer artifact valid for about an
 * hour; storing it would put a live login into the database for a window in
 * which it is worthless to us, since the person proves the same thing again on
 * the next visit.
 */
class ExternalIdentity extends Model
{
    /**
     * The only provider this build holds an identity for.
     *
     * The value set is `hassal.auth.external_identity_providers`, which the
     * column's CHECK constraint was built from: a name in code that the database
     * would reject is the drift this project has been removing everywhere else,
     * so the constant is asserted against the config rather than trusted.
     */
    public const PROVIDER_GOOGLE = 'google';

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'email',
        'email_verified_at',
        'linked_at',
        'last_verified_at',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'linked_at' => 'datetime',
        'last_verified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
