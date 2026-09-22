<?php

namespace App\Models;

use App\Services\TenantContext;
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
        ];
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

    public function roleInOrganization(int|string $organizationId): ?string
    {
        $membership = $this->memberships()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->first();

        return $membership ? $membership->role : null;
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
        if ($this->standingOfTheAccount($permission, $guardName)) {
            return true;
        }

        $name = $permission instanceof Permission
            ? $permission->name
            : (is_string($permission) ? $permission : null);

        // Only a name the company matrix can speak for is asked of the matrix.
        // Anything else — a platform capability, or a name neither list knows —
        // was already answered `no` above, and answering it from a membership row
        // would widen the platform's own surface.
        return $name !== null
            && OrganizationMembership::isBusinessPermission($name)
            && $this->grantedByCurrentOrganization($name);
    }

    /**
     * What the account holds globally: direct grants and role grants.
     *
     * `filterPermission()`, `hasDirectPermission()` and `hasPermissionViaRole()`
     * are the trait's own resolution steps, and they are callable from here
     * because trait methods become methods of this class. The unknown-name
     * exception the trait throws is left to propagate: `checkPermissionTo()`
     * turns it into `false`, and a caller that wants the distinction keeps it.
     */
    private function standingOfTheAccount($permission, ?string $guardName): bool
    {
        if ($this->getWildcardClass()) {
            return $this->hasWildcardPermission($permission, $guardName);
        }

        $resolved = $this->filterPermission($permission, $guardName);

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
        /** @var Collection $permissions */
        $permissions = $this->permissions->merge($this->getPermissionsViaRoles());

        foreach ($this->currentOrganizationPermissions() as $name) {
            if ($permissions->contains('name', $name)) {
                continue;
            }

            $permissions->push(Permission::findByName($name, $this->getDefaultGuardName()));
        }

        return $permissions->sortBy('name')->values();
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

        return OrganizationMembership::permissionsFor($membership->role);
    }

    private function grantedByCurrentOrganization(string $permission): bool
    {
        return in_array($permission, $this->currentOrganizationPermissions(), true);
    }
}
