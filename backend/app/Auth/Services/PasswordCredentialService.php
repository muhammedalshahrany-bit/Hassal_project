<?php

namespace App\Auth\Services;

use App\Identity\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Writing an account's password, and closing the sessions the old one opened.
 *
 * Three doors change a credential: a reset link, the account's own
 * change-password form, and a deactivation from the platform console. Each of
 * them has to revoke the tokens the previous password minted, and each of them
 * has a different right to keep the token in front of it — a reset keeps
 * nothing, a change keeps the caller's own session so a person is not logged
 * out by their own security action. Those two facts are one method's optional
 * argument, not three copies of a query that can drift.
 */
class PasswordCredentialService
{
    /**
     * Give the account this password and revoke every session it opened before.
     *
     * `$keepTokenId` is the token making the call, when there is one: a stolen
     * token left alive after a password change makes the change a formality,
     * while a person logging their own device out mid-form is punished for
     * doing the right thing.
     */
    public function setPassword(User $user, string $password, ?int $keepTokenId = null): void
    {
        // The column carries a `hashed` cast, which leaves an already-hashed
        // value alone; `Hash::make()` here is what the reset and change doors
        // have always written, and `forceFill` is how a credential is set
        // without the rest of the row being swept along with it.
        $user->forceFill(['password' => Hash::make($password)])->save();

        $this->revokeSessions($user, $keepTokenId);
    }

    /**
     * End the account's sessions, optionally sparing one.
     */
    public function revokeSessions(User $user, ?int $keepTokenId = null): void
    {
        $user->tokens()
            ->when($keepTokenId, fn ($query) => $query->whereKeyNot($keepTokenId))
            ->delete();
    }
}
