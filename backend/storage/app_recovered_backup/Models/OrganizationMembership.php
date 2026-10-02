<?php

namespace App\Models;

use App\Support\RbacScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationMembership extends Model
{
    use HasFactory;

    /** The standing that cannot simply be handed out, and must never run out. */
    public const ROLE_OWNER = 'owner';

    /**
     * Resolved standings, memoised on the row.
     *
     * A single API request asks `User::hasPermissionTo()` a dozen times — once per
     * middleware and once per branch that decides what a payload shows — and each
     * ask would otherwise read a role row, its grants, and this member's
     * overrides. The memo lives on the model instance rather than in a class
     * cache: `TenantContext` hands the same membership object to every check in a
     * request, so one entry per request is all the reuse there is to be had, and a
     * cache that outlives the request would be reading last week's answer — or, in
     * a test suite that reuses ids after a rollback, somebody else's.
     *
     * The generation is the exception that proves the rule: a request that edits a
     * matrix and then acts inside it must not keep the authority it had before the
     * edit, so the doors that change grants call {@see self::flushResolutions()},
     * which no instance's stamp matches any more.
     */
    private ?array $resolvedPermissions = null;

    private static int $resolutionGeneration = 0;

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
        'is_active',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The standings the platform names, as `key => label`.
     *
     * These are the *defaults*, and they are also still the fallback: a company
     * that has not been provisioned into `organization_roles` — a fixture, a row
     * created before the table existed — is read against this list, so isolation
     * never turns into a company with no authority at all. Once the rows exist they
     * answer for what the standing is worth, and a company's own names appear
     * beside these through {@see self::isStaffableRole()}.
     *
     * Every reader of this goes through here rather than writing the list out: the
     * member screens, the provisioning that copies the defaults into rows, and the
     * tests that assert the two agree. They once disagreed silently, and the
     * disagreement stayed invisible until somebody tried to save a row whose stored
     * value a validator had never accepted.
     */
    public static function roles(): array
    {
        /** @var array<string, string> $roles */
        $roles = (array) config('rbac.membership_roles');

        return $roles === [] ? [self::ROLE_OWNER => 'مالك المنشأة'] : $roles;
    }

    /**
     * May this company be staffed with somebody holding this standing?
     *
     * True for one of the platform's names — which every company keeps whether or
     * not it has been provisioned — and for a key the company gave itself. The
     * vocabulary used to be a CHECK constraint on the column; a constraint cannot
     * reference another table, so once a company may name a standing of its own the
     * check has to live where the organization id is known, which is the write
     * door. This is that door's one question, and both of them ask it.
     */
    public static function isStaffableRole(int|string $organizationId, mixed $role): bool
    {
        if (!is_string($role) || $role === '') {
            return false;
        }

        return self::isRole($role) || OrganizationRole::existsForKey($organizationId, $role);
    }

    /**
     * The `in:` rule for a screen that offers the platform's own standings.
     *
     * Only a validator that has no company in front of it should still use this;
     * a member screen is inside an organization and answers to
     * {@see self::isStaffableRole()}.
     */
    public static function roleRule(): string
    {
        return 'in:' . implode(',', self::roleNames());
    }

    /**
     * Is this name in the catalog at all?
     *
     * The single question the scope boundary keeps asking: a row may carry a name
     * only if the catalog declares it, because a name nobody declared enforces
     * nothing and looks like a capability in every screen that lists it.
     */
    public static function isCatalogued(string $permission): bool
    {
        return in_array($permission, (array) config('rbac.permissions'), true);
    }

    /** @return list<string> */
    public static function roleNames(): array
    {
        return array_keys(self::roles());
    }

    public static function isRole(mixed $role): bool
    {
        return is_string($role) && isset(self::roles()[$role]);
    }

    /**
     * What a standing is worth by default, as the platform declares it.
     *
     * The list lives in `config/rbac.php` and it is the *default*, not the answer
     * for a company that has been provisioned: {@see self::effectivePermissions()}
     * is what the runtime reads, and it prefers the company's own rows. Returning
     * names rather than Permission models keeps this readable without a query.
     *
     * @return list<string>
     */
    public static function permissionsFor(?string $role): array
    {
        if ($role === null) {
            return [];
        }

        /** @var array<string, list<string>> $matrix */
        $matrix = (array) config('rbac.membership_permissions');

        return array_values($matrix[$role] ?? []);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(OrganizationMemberPermission::class, 'organization_membership_id');
    }

    /**
     * What this person may do inside this company, as the company says.
     *
     * Three inputs, in this order, and the order is the whole rule:
     *
     *   1. the standing's grants — the company's `organization_roles` row for this
     *      membership's key, or the platform default when the company has no such
     *      row;
     *   2. plus this member's `grant` rows, which is how a company lifts one
     *      person above their standing without inventing a standing for them;
     *   3. minus this member's `deny` rows, which win. A row written about one
     *      person is more specific than a row written about a class of people, and
     *      the more specific statement is the one that was meant.
     *
     * Then the scope filter, applied on the way out rather than only at the write
     * door. A platform name must not be reachable from inside a company whatever
     * filled the table — migration, seed, console — and this method is the single
     * reader of the organization side, so filtering here means there is no path
     * that skips it.
     *
     * @return list<string>
     */
    public function effectivePermissions(): array
    {
        if ($this->resolvedPermissions !== null
            && $this->resolvedPermissions['generation'] === self::$resolutionGeneration) {
            /** @var list<string> $cached */
            $cached = $this->resolvedPermissions['permissions'];

            return $cached;
        }

        $roleRow = OrganizationRole::lookup($this->organization_id, $this->role);

        $names = $roleRow === null
            ? self::permissionsFor($this->role)
            : $roleRow->permissionNames();

        $this->loadMissing('overrides.permission');

        $grants = [];
        $denials = [];

        foreach ($this->overrides as $override) {
            $name = $override->permission?->name;

            if ($name === null) {
                continue;
            }

            if ($override->effect === OrganizationMemberPermission::EFFECT_DENY) {
                $denials[] = $name;
            } else {
                $grants[] = $name;
            }
        }

        $resolved = array_values(array_filter(
            array_unique([...$names, ...$grants]),
            static fn (string $name): bool => !in_array($name, $denials, true)
                && RbacScope::exercisableInsideOrganization($name)
                && self::isCatalogued($name)
        ));

        $this->resolvedPermissions = ['generation' => self::$resolutionGeneration, 'permissions' => $resolved];

        return $resolved;
    }

    /**
     * Does this person, in this company, cover this capability?
     *
     * The question the staffing doors used to ask of the config matrix through a
     * role string. A custom standing that carries `members.manage` now answers it
     * the same way a seeded one does, which is the point of moving the matrix into
     * rows: `canManage()` could only ever know the five names the platform wrote.
     */
    public function covers(string $permission): bool
    {
        return in_array($permission, $this->effectivePermissions(), true);
    }

    /**
     * Forget every resolved standing.
     *
     * Called by each door that changes a role's grants or a member's overrides.
     * Without it a request that edits its own company would keep acting on the
     * authority it had before the edit — which is not only wrong, it is the
     * specific wrong of a role you have just stripped still letting you strip
     * another.
     */
    public static function flushResolutions(): void
    {
        self::$resolutionGeneration++;
    }

    /**
     * Every permission a company may hold at all.
     *
     * The boundary of business authority, stated as a scope rather than as the
     * union of the standings the platform happens to have seeded: a name is on the
     * company side when it is *not* one the platform kept for itself, which is the
     * rule and not a description of what has been written down. A `both` name
     * belongs here — reading your own books is a company act — and a test asserts
     * the other direction: none of the platform's own powers (moderating a listing,
     * verifying a business, ruling a dispute, editing the platform's settings) ever
     * appear on the company side.
     *
     * @return list<string>
     */
    public static function businessPermissions(): array
    {
        return RbacScope::namesReachableInsideOrganization();
    }

    public static function isBusinessPermission(string $permission): bool
    {
        return self::isCatalogued($permission) && RbacScope::exercisableInsideOrganization($permission);
    }

    /**
     * Who may change the staffing of a company.
     *
     * A platform SuperAdmin is allowed everywhere by definition, so callers that
     * already know that pass the flag in rather than repeating the check here.
     *
     * @deprecated the role string carries only the platform's defaults; an
     *             organization's own standing, and its individual overrides, are
     *             read by {@see self::covers()} from a membership row. Kept for the
     *             callers that genuinely have no row — the tests that assert the
     *             seeded defaults are what the config declares.
     */
    public static function canManage(?string $membershipRole, bool $isPlatformAdmin = false): bool
    {
        if ($isPlatformAdmin) {
            return true;
        }

        return in_array('members.manage', self::permissionsFor($membershipRole), true);
    }
}
