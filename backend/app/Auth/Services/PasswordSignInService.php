<?php

namespace App\Auth\Services;

use App\Identity\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * What a named password proves about this platform's own accounts.
 *
 * `GoogleSignInService` answers the same question for a provider's assertion,
 * and the two doors have to agree: an account that is suspended is refused by
 * both, and an account opened from a provider has no local password on either.
 * Keeping that answer here means the HTTP layer only phrases it — the refusal
 * codes and the sentences the client is promised stay at the edge, while the
 * rules of who may sign in do not depend on which route was called.
 */
class PasswordSignInService
{
    /**
     * The account a login string names, and whether it may open a session.
     *
     * `INVALID_CREDENTIALS` deliberately covers three different facts — no such
     * account, a password that does not match, and an account that
     * authenticates somewhere else instead. Telling them apart would let
     * anybody with a list of emails and phones confirm which of them trade
     * here, which is why the door that knows the difference still answers with
     * the sentence that does not.
     *
     * The inactive case travels with the status because the client-facing
     * sentence names it: a person who was paused wants to know they are paused,
     * and their own sign-in attempt is not somebody else's probe.
     *
     * @return array{user?: User, refusal?: string, status?: string}
     */
    public function attempt(string $login, string $password): array
    {
        $user = User::query()
            ->where('email', $login)
            ->orWhere('phone', $login)
            ->first();

        if (!$user || !$this->matches($user, $password)) {
            return ['refusal' => 'INVALID_CREDENTIALS'];
        }

        if ($user->status !== 'active') {
            return ['refusal' => 'ACCOUNT_INACTIVE', 'status' => $user->status];
        }

        return ['user' => $user];
    }

    /**
     * Does this password open this account?
     *
     * `Hash::check($x, null)` is a fatal in PHP 8, and a null hash is the
     * ordinary state of an account opened from a provider's assertion rather
     * than a broken row. A platform that crashed on the question would answer
     * it — an error page confirms the account exists — so the case is a plain
     * "no", and both this and the change-password door read it from here.
     */
    public function matches(User $user, string $password): bool
    {
        return $user->password !== null && Hash::check($password, $user->password);
    }
}
