<?php

namespace App\Auth\Services;

use App\Auth\Support\AccountRequirements;
use App\Core\Tenant\TenantContext;
use App\Identity\Models\User;
use App\Organizations\Models\OrganizationMembership;
use Illuminate\Support\Collection;
use Laravel\Sanctum\NewAccessToken;

/**
 * The body every sign-in answers with, built in one place.
 *
 * Login, Google and registration all hand the client the same thing: a token,
 * the account, the businesses it belongs to, and what is still outstanding.
 * Three copies of that shape is how one of them drifts — and the
 * outstanding-fields list is exactly the part a screen needs to be right
 * about, because it is the difference between "welcome" and "we cannot let
 * you trade yet". The account block is the same method `/auth/me` reads for
 * the same reason: one person, one shape.
 *
 * `$device` is the optional third thing a sign-in carries: the handset or
 * browser it came from, recorded against the token this payload returns.
 * Null keeps the pre-device behaviour — a session that works and is not
 * listed, because nothing named a device to list.
 */
class SessionService
{
    public function __construct(
        private readonly DeviceRegistry $devices,
        private readonly AccountRequirements $accountRequirements,
    ) {}

    /**
     * Issue the session, record the device, and compose the answer.
     *
     * @param  array{key: string, name: string, platform: string, biometric_unlock: bool}|null  $device
     * @return array<string, mixed>
     */
    public function issue(User $user, string $deviceName, ?array $device = null): array
    {
        $memberships = $user->activeMemberships();
        $defaultMembership = $memberships->first();
        $defaultOrg = $defaultMembership?->organization;

        $token = $this->issueToken($user, $device['name'] ?? $deviceName);
        // The row is written after the token exists because it points at it.
        // Nothing in the payload depends on the result: a client that sent no
        // device simply is not listed, exactly as before this field existed.
        $this->devices->record($user, $device, $token->accessToken->id);

        return [
            'user' => $this->account($user, $defaultMembership),
            'active_organization' => $defaultOrg,
            'organizations' => $this->organizationList($memberships),
            'token' => $token->plainTextToken,
            'profile_completion' => $this->profileCompletion($user, $memberships),
        ];
    }

    /**
     * The account as every client reads it.
     *
     * One shape for the sign-in payload and for `/auth/me`, because those two
     * blocks are the same person: where they are written separately, one grows a
     * field the other never sends, and a screen ends up reading a value that is
     * only present after a second call.
     *
     * `$context` is the business whose standing decides the capabilities. A
     * fresh session answers for the company it lands in, which is why the
     * context is set by hand here; `/auth/me` arrives with that context already
     * resolved by the tenant middleware and passes none.
     *
     * @return array<string, mixed>
     */
    public function account(User $user, ?OrganizationMembership $context = null): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'national_id' => $user->national_id,
            'status' => $user->status,
            'roles' => $user->getRoleNames(),
            // The capabilities of the business this answer speaks for, because
            // that is what the membership row in front of them says. Answering
            // with the global rows alone would tell the owner of a company who
            // holds no platform role that they can do nothing, and the screen
            // would then have to guess — from a client, about a server.
            'permissions' => $context
                ? $this->withOrganizationContext($context, fn () => $user->getAllPermissions()->pluck('name'))
                : $user->getAllPermissions()->pluck('name'),
            // Signed-in from the first frame: the switches behind this map
            // decide whether the business name is printed at all, and a client
            // that only learned the answer on the next call would have printed
            // it for a moment to the person who turned it off.
            'preferences' => $user->preferences(),
        ];
    }

    /**
     * The businesses this account holds a seat in, in the shape a list shows them.
     *
     * @param  Collection<int, OrganizationMembership>  $memberships
     */
    public function organizationList(Collection $memberships): array
    {
        return $memberships->map(fn ($m) => [
            'id' => $m->organization->id,
            'name_ar' => $m->organization->name_ar,
            'name_en' => $m->organization->name_en,
            'type' => $m->organization->type,
            'role' => $m->role,
            'status' => $m->organization->status,
        ])->all();
    }

    /**
     * What the account still owes this deployment, said once and read everywhere.
     *
     * A sign-in response and `/auth/me` carry the same block because a client
     * should never have to keep its own copy of the rule — the usual way a form
     * ends up asking for something the server has stopped requiring, or staying
     * quiet while an action is refused.
     *
     * `has_password` is in here for the same reason: an account opened from a
     * provider's assertion has no local password, and offering it a "change
     * password" form would be offering it a door it cannot open.
     *
     * @param  Collection<int, OrganizationMembership>  $memberships
     * @return array<string, mixed>
     */
    public function profileCompletion(User $user, Collection $memberships): array
    {
        $missing = $this->accountRequirements->missingFields($user);

        return [
            'required' => $missing !== [] || $memberships->isEmpty(),
            'missing_fields' => $missing,
            'has_organization' => $memberships->isNotEmpty(),
            'has_password' => $user->password !== null,
        ];
    }

    /**
     * Issue a session token that expires.
     *
     * Sanctum's createToken() takes an expiry as its third argument, and
     * config/hassal.php declares `auth.token_ttl_minutes` — but the calls here
     * passed neither, so every token issued by this platform was immortal and
     * the setting gated nothing. A configuration value nothing reads is not a
     * control, and a bearer token that never dies is a credential that survives
     * a password change, a revoked device and a departed employee.
     *
     * The access token is handed back whole rather than as its plain-text string,
     * because the device that just signed in has to be pointed at the credential
     * it now holds: a list of sessions with no way to name the token inside each
     * one is a list you can look at and not act on. Sanctum's answer carries
     * both halves — the row and the one string that will ever be shown.
     */
    private function issueToken(User $user, string $deviceName): NewAccessToken
    {
        $minutes = max(1, (int) config('hassal.auth.token_ttl_minutes', 720));

        return $user->createToken($deviceName, ['*'], now()->addMinutes($minutes));
    }

    /**
     * Answer for one organization, then put the context back.
     *
     * `User::hasPermissionTo()` and `getAllPermissions()` consult
     * `TenantContext`, which is a per-request holder normally filled by the
     * `tenant` middleware. Login and registration are public routes with no such
     * middleware, so the membership being handed to the client is set here for the
     * length of one callback and restored after — including the "there was no
     * context before" case, which must not be left pointing at a company.
     */
    private function withOrganizationContext(?OrganizationMembership $membership, callable $callback): mixed
    {
        if (!$membership) {
            return $callback();
        }

        $previousOrganization = TenantContext::get();
        $previousMembership = TenantContext::membership();

        TenantContext::set($membership->organization ?? $membership->organization()->first(), $membership);

        try {
            return $callback();
        } finally {
            if ($previousOrganization) {
                TenantContext::set($previousOrganization, $previousMembership);
            } else {
                TenantContext::clear();
            }
        }
    }
}
