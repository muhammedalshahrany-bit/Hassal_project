<?php

namespace App\Auth\Services;

use App\Auth\Models\ExternalIdentity;
use App\Identity\Models\User;
use App\Platform\Services\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What the platform decides when Google hands it a verified person.
 *
 * The controller has already had the assertion's signature and audience
 * checked by `GoogleIdTokenVerifier`; this class makes the account decision,
 * which is this platform's to make and not the provider's:
 *
 *   * an identity row already names this subject — the same person, again;
 *   * no row, but a verified address matches an account that holds no other
 *     Google identity — the address is linked. This is the same proof the
 *     password-reset flow already accepts (control of the mailbox), so it
 *     grants no authority the platform did not already concede. An account
 *     already bound to a *different* subject is refused rather than
 *     re-bound: two people cannot own one account because a mailbox changed
 *     hands at the provider;
 *   * nothing matches — an account is created if the operator left
 *     self-provisioning on, and refused if they turned it off to keep the
 *     platform on an invitation list.
 *
 * A new account is given no password, no role and no organization. Its
 * `password` column stays NULL, which is the honest statement that this
 * person does not authenticate here; `login()` reads that and says so.
 */
class GoogleSignInService
{
    public function __construct(
        private readonly SystemSettingsService $settings,
    ) {}

    /**
     * Resolve a verified assertion to a person, in one transaction.
     *
     * The result is a small closed vocabulary the controller translates
     * mechanically: `user` on success; `refusal` when the platform itself
     * withholds the door (an inactive account); `conflict` when this Google
     * person and this platform account cannot be joined; `account_created`
     * when the request opened a row that did not exist a moment ago — the
     * fact a client needs to choose between the setup flow and the sign-in one.
     *
     * @param  array{subject: string, email: string, name: string}  $assertion
     * @return array<string, mixed>
     */
    public function resolve(array $assertion): array
    {
        return DB::transaction(function () use ($assertion) {
            $identity = ExternalIdentity::query()
                ->where('provider', ExternalIdentity::PROVIDER_GOOGLE)
                ->where('provider_user_id', $assertion['subject'])
                ->first();

            if ($identity) {
                // Checked before anything is written, not after. A suspended
                // account that gets its identity row refreshed has been treated
                // as though it signed in, and the refusal on the way out leaves
                // the record of that behind. Access is this platform's decision,
                // and it is made before its own books are changed.
                $owner = $identity->user;

                if ($owner === null || $owner->status !== 'active') {
                    return ['refusal' => 'ACCOUNT_INACTIVE', 'user' => null, 'identity' => null, 'account_created' => false];
                }

                $identity->forceFill([
                    'email' => $assertion['email'],
                    'email_verified_at' => now(),
                    'last_verified_at' => now(),
                ])->save();

                return ['user' => $owner, 'identity' => $identity, 'account_created' => false];
            }

            $user = User::where('email', $assertion['email'])->first();
            $accountCreated = false;

            if ($user) {
                $existing = $user->externalIdentities()
                    ->where('provider', ExternalIdentity::PROVIDER_GOOGLE)
                    ->first();

                if ($existing) {
                    // The address is here and belongs to someone else's Google
                    // account. Merging would hand that person's business over.
                    return ['conflict' => 'GOOGLE_ACCOUNT_ALREADY_LINKED', 'user' => null, 'identity' => null, 'account_created' => false];
                }

                if ($user->status !== 'active') {
                    return ['refusal' => 'ACCOUNT_INACTIVE', 'user' => null, 'identity' => null, 'account_created' => false];
                }
            } else {
                if (! filter_var(
                    (string) $this->settings->get('auth.google_auto_register', '1'),
                    FILTER_VALIDATE_BOOLEAN
                )) {
                    return ['conflict' => 'GOOGLE_ACCOUNT_NOT_PROVISIONED', 'user' => null, 'identity' => null, 'account_created' => false];
                }

                $accountCreated = true;

                $user = User::create([
                    'name' => $assertion['name'] ?: Str::before($assertion['email'], '@'),
                    'email' => $assertion['email'],
                    // No password is invented for this row. A random string
                    // would sit in the column looking like a credential while
                    // being one nobody holds; NULL is the truth, and `login()`
                    // now reads it as the truth.
                    'password' => null,
                    'status' => 'active',
                    // Google said it verified this address, and its word about
                    // its own mailbox is the thing this column means.
                    'email_verified_at' => now(),
                ]);
            }

            $identity = ExternalIdentity::create([
                'user_id' => $user->id,
                'provider' => ExternalIdentity::PROVIDER_GOOGLE,
                'provider_user_id' => $assertion['subject'],
                'email' => $assertion['email'],
                'email_verified_at' => now(),
                'linked_at' => now(),
                'last_verified_at' => now(),
            ]);

            return ['user' => $user, 'identity' => $identity, 'account_created' => $accountCreated];
        });
    }
}
