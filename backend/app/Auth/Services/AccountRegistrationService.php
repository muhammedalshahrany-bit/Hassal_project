<?php

namespace App\Auth\Services;

use App\Identity\Models\User;

/**
 * Opening an account from a password sign-up.
 *
 * The row is written here rather than in the controller because the same
 * decisions have to hold whoever asks for an account: `GoogleSignInService`
 * opens one from a provider's assertion, the installer opens the platform's
 * own, and this class opens the one a person asks for themselves. What is
 * *not* done in any of them is the point, and it is stated once here instead
 * of being implied by the absence of a line at each door.
 */
class AccountRegistrationService
{
    /**
     * @param array<string, mixed> $validated the fields the sign-up form may carry
     */
    public function create(array $validated): User
    {
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'national_id' => $validated['national_id'] ?? null,
            'password' => $validated['password'],
            'status' => 'active',
        ]);

        // No role is attached to a new account. The `assignRole('BuyerStaff')`
        // that used to sit at this door granted nothing even when it did: it
        // stamped a company standing onto the account, which then followed the
        // person into businesses they had no relation to. Standing comes from a
        // membership row, so an account that has signed up and not yet joined or
        // created a business holds only the declared person capabilities —
        // liking a listing and asking a public question in its own name — and
        // every trading door still asks it to name a company first.
        //
        // No organization is created here either. A business is opened in one
        // place, by one request, against one set of rules
        // (`OrganizationController::store`); see that door's docblock for the
        // weaker second door this replaced.

        return $user;
    }
}
