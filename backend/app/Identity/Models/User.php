<?php

namespace App\Identity\Models;

use App\Core\Models\AuditLog;
use App\Auth\Models\ExternalIdentity;
use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationMembership;

use App\Core\Tenant\TenantContext;
use App\Auth\Support\PreferenceCatalog;
use App\Core\Rbac\RbacScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Auth\Passwords\CanResetPassword as CanResetPasswordTrait;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements CanResetPassword
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes, CanResetPasswordTrait;

    /**
     * The role the installation itself is run by.
     *
     * Named once because about thirty doors ask the question, and every one of
     * them was written with the word typed out. A role name is a string that a
     * config file, a seeder, a migration and a screen all have to agree on; the
     * day one of them says `super_admin` the checks stop meaning the same thing,
     * and the failure is silent — a gate that asks about a role nobody holds
     * simply answers no.
     *
     * Holding this role is not how a capability is granted. Spatie gives
     * `SuperAdmin` every permission row (`config/rbac.php` declares it as `*`),
     * so an access check asks `can(...)` and never about a role. This method
     * answers a different question — *is this the platform's own account* — which
     * some rules genuinely need: whether a business's papers may be read from
     * outside any tenant, whether a missing company id is a 404 or a refusal,
     * whether the last operator may be cut out of the system.
     */
    public const PLATFORM_OPERATOR_ROLE = 'SuperAdmin';

    public function isPlatformOperator(): bool
    {
        return $this->hasRole(self::PLATFORM_OPERATOR_ROLE);
    }

    protected $fillable = [
        'name',
        'email',
        'phone',
        'national_id',
        'status',
        'password',
        'email_verified_at',
        'phone_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            // A person's own display choices, kept server-side so a phone and a
            // browser show the same account. `null` is "never asked", and
            // `preferences()` is what turns that into the catalog's defaults.
            'preferences' => 'array',
        ];
    }

    /**
     * This account's preferences, filled in from the compiled defaults.
     *
     * The client is handed a complete map rather than the stored fragment so it
     * never has to carry a second copy of the defaults — which is how a phone
     * and a web tab end up disagreeing about what "no setting yet" means.
     *
     * @return array<string, mixed>
     */
    public function preferences(): array
    {
        $stored = is_array($this->preferences) ? $this->preferences : [];

        return PreferenceCatalog::merge($stored, [])['preferences'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /**
     * The accounts at outside providers that open sessions as this person.
     *
     * Zero rows is the ordinary case: an account created with a password and
     * never linked to anything. One row is a person who signs in with a
     * provider's assertion, and that row — not `email_verified_at`, not a
     * remembered password — is what a repeat sign-in is matched against.
     */
    public function externalIdentities(): HasMany
    {
        return $this->hasMany(ExternalIdentity::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_memberships')
            ->withPivot('role', 'is_active', 'joined_at')
            ->withTimestamps();
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function belongsToOrganization(int|string $organizationId): bool
    {
        return $this->memberships()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Every business this account currently holds a seat in, each one loaded.
     *
     * `is_active` is the whole of what "currently" means: a removed member's row
     * stays for the audit trail, and an answer built from the rows alone would
     * hand a departed employee back the list of companies they worked for.
     * Written once because the sign-in payload, `/auth/me` and the tenant
     * middleware all read exactly this list, and a version of it that drifted in
     * one of them is a person shown a business they can no longer act in.
     *
     * @return Collection<int, OrganizationMembership>
     */
    public function activeMemberships(): Collection
    {
        return $this->memberships()
            ->where('is_active', true)
            ->with('organization')
            ->get();
    }

    /**
     * This account's seat in one business — or, with no business named, its
     * first one, which is the company a session lands in.
     */
    public function firstActiveMembership(int|string|null $organizationId = null): ?OrganizationMembership
    {
        return $this->memberships()
            ->when(
                $organizationId !== null,
                fn ($query) => $query->where('organization_id', $organizationId)
            )
            ->where('is_active', true)
            ->first();
    }

    public function roleInOrganization(int|string $organizationId): ?string
    {
        return $this->firstActiveMembership($organizationId)?->role;
    }

    /**
     * What this account may do, taking the current organization into account.
     *
     * The single hook every check in this application funnels through: Spatie
     * registers `Gate::before` → `checkPermissionTo()` → `hasPermissionTo()`, and
     * the route middleware `permission:…` calls `canAny()`, which lands there
     * too. Governing it here therefore governs the middleware, `can()`, and
     * `/auth/me` at once — which is the point: a permission only the button
     * honours is decoration (§36 of the brief), and a permission two code paths
     * interpret differently is a bug waiting for the quieter of the two.
     *
     * Business capabilities come from the membership row of the organization the
     * request is acting as, so leaving a company removes the access without
     * anybody having to remember to uncheck a role, and the same person can own
     * one business and merely review another. Platform capabilities — moderating
     * a listing, verifying a business, ruling a dispute, editing the platform's
     * settings, reading every company's books — are not in that matrix at all, so
     * they can only come from a role, which is what `standingOfTheAccount()`
     * answers with. SuperAdmin needs no branch: its role holds every permission
     * row, and nothing here grants access by matching a role name.
     *
     * The body of the inherited check is replayed rather than called through
     * `parent::`, because `hasPermissionTo()` arrives on this class from the
     * `HasPermissions` trait — a trait method is the class's own method, so there
     * is no parent to reach; `parent::hasPermissionTo()` is a fatal
     * "call to undefined method", which is what this line looked like before it
     * was read back from a failing request.
     */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        $name = $permission instanceof Permission
            ? $permission->name
            : (is_string($permission) ? $permission : null);

        if ($this->standingOfTheAccount($permission, $guardName, $name)) {
            return true;
        }

        // Only a name the company side may speak for is asked of the company. A
        // platform capability is absent from every organization role's grants by
        // the write doors, and absent from this check whatever those rows say, so
        // no combination of company standings produces one.
        if ($name !== null
            && RbacScope::exercisableInsideOrganization($name)
            && $this->grantedByCurrentOrganization($name)) {
            return true;
        }

        // The consumer standing (§1 of the product brief): a person with no
        // organization in context still owns their clicks. Liking a listing and
        // asking a public question attribute to `user_id` alone, so no company
        // grant is missing — there is no company. Three things bound this
        // branch so it cannot become a back door: the name must be on the
        // declared person-capability list, a tenant in context switches it off
        // (someone acting *as* a business is bound by that business's matrix,
        // even one that does not carry the capability), and a suspended account
        // is refused here as `EnsureAccountIsActive` refuses it on the route.
        return $name !== null
            && TenantContext::id() === null
            && RbacScope::isPersonCapability($name)
            && $this->getAttribute('status') === 'active';
    }

    /**
     * What the account holds globally: direct grants and role grants.
     *
     * `filterPermission()`, `hasDirectPermission()` and `hasPermissionViaRole()`
     * are the trait's own resolution steps, and they are callable from here
     * because trait methods become methods of this class. The unknown-name
     * exception the trait throws is left to propagate: `checkPermissionTo()`
     * turns it into `false`, and a caller that wants the distinction keeps it.
     *
     * The scope rule is applied to the *direct* rows and only those. A company
     * capability stamped onto the account is the fault this whole layer was
     * rebuilt for — it followed the person into businesses they had no relation
     * to and survived their leaving — so such a row is read as nothing here even
     * if a database console wrote one. Grants that come through a role are not
     * filtered, because what a platform role may hold is already fixed by
     * `config/rbac.php` (and `RbacMatrixTest` asserts none of it is a company
     * capability); the one role whose declared grants are the whole catalog is
     * the platform owner, and it is omniscient because the config says `'*'`, not
     * because a check matches its name.
     */
    private function standingOfTheAccount($permission, ?string $guardName, ?string $name): bool
    {
        if ($this->getWildcardClass()) {
            return $this->hasWildcardPermission($permission, $guardName);
        }

        $resolved = $this->filterPermission($permission, $guardName);

        if ($name !== null && !RbacScope::exercisableByAccount($name)) {
            return $this->hasPermissionViaRole($resolved);
        }

        return $this->hasDirectPermission($resolved) || $this->hasPermissionViaRole($resolved);
    }

    /**
     * Every capability this account holds right now, the organization context
     * included.
     *
     * `/auth/me` answers with this and the console renders its buttons from that
     * answer. The trait's version sees only the global rows, so the owner of a
     * company holding no platform role would have been told they can do nothing —
     * which is how a correctly enforced backend still ends up with a screen that
     * has to guess, from the client side, about the server side.
     *
     * Assembled from the same parts the trait uses, for the reason given above:
     * there is no `parent::` to call on a trait method.
     */
    public function getAllPermissions(): Collection
    {
        /**
         * Assembled under the same rule `hasPermissionTo()` applies, for the same
         * reason given there: a list this method answers with is what the console
         * renders buttons from, and a list that carries a capability the check
         * refuses is how a screen ends up offering a door that answers 403.
         *
         * @var Collection $permissions
         */
        $permissions = $this->getPermissionsViaRoles()->merge(
            $this->permissions->filter(
                fn (Permission $permission): bool => RbacScope::exercisableByAccount($permission->name)
            )
        );

        foreach ($this->currentOrganizationPermissions() as $name) {
            if ($permissions->contains('name', $name)) {
                continue;
            }

            $permissions->push(Permission::findByName($name, $this->getDefaultGuardName()));
        }

        // The consumer standing, listed exactly where `hasPermissionTo()` would
        // honour it and nowhere else: no organization in context, an active
        // account, names from the declared person-capability list. `/auth/me`
        // answers from this method, so a consumer's client learns it may like
        // and ask without the server ever being asked to explain a 403 that
        // does not exist.
        if (TenantContext::id() === null && $this->getAttribute('status') === 'active') {
            foreach (RbacScope::personCapabilities() as $name) {
                if ($permissions->contains('name', $name)) {
                    continue;
                }

                $permissions->push(Permission::findByName($name, $this->getDefaultGuardName()));
            }
        }

        // Rebuilt as an Eloquent collection rather than returned as assembled: the
        // assembly starts from `getPermissionsViaRoles()`, which is a plain Support
        // collection in the trait, and the declared return type of this method —
        // Spatie's own — is the Eloquent one.
        return new Collection($permissions->sortBy('name')->values()->all());
    }

    /**
     * The permissions of this account's own standing in the organization the
     * request is acting as.
     *
     * Three conditions, all of them about whose authority is being read: there
     * has to be an organization context, the membership row in it has to belong to
     * this account (a context resolved for somebody else grants nothing), and it
     * has to be active. `ResolveOrganizationContext` already refuses a header for
     * a company this token does not belong to; this is the second lock, so a
     * future caller that sets the context itself cannot hand out another person's
     * standing by accident.
     *
     * The row, not the role string, is then asked what it is worth — see
     * `OrganizationMembership::effectivePermissions()`: the company's own standing
     * definition, plus this member's grants, minus this member's denials, and
     * nothing the platform has kept for itself.
     *
     * @return list<string>
     */
    public function currentOrganizationPermissions(): array
    {
        $membership = TenantContext::membership();

        if (!$membership
            || !$membership->is_active
            || (int) $membership->user_id !== (int) $this->getKey()) {
            return [];
        }

        return $membership->effectivePermissions();
    }

    private function grantedByCurrentOrganization(string $permission): bool
    {
        return in_array($permission, $this->currentOrganizationPermissions(), true);
    }
}
