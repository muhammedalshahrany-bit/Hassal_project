<?php

namespace App\Organizations\Models;

use App\Core\Rbac\RbacScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * A standing one company staffs from.
 *
 * This is the half of the isolation the config file cannot do. `config/rbac.php`
 * declares which names exist and which of them a company may hold at all; it
 * cannot say what «موظف» is worth at a particular business, because from here on
 * that is the company's own decision and not the platform's. The five standings
 * the platform seeds (`owner`, `admin`, `staff`, `finance`, `auditor`) arrive
 * here as ordinary rows marked `is_system`, worth whatever the config matrix said
 * on the day they were provisioned, and after that the company edits them like any
 * other role. `owner` keeps its protection in code and not in a column: it is the
 * one standing whose grants may not be emptied, because a company with an owner
 * who can do nothing has nobody left who can appoint another.
 *
 * Two doors reach this table and they check the same thing, which is why the check
 * lives here rather than in either caller. A platform-scoped name — approving a
 * listing, verifying a business, ruling a dispute, staffing accounts — can never
 * be attached to a company standing, whatever an admin screen posts, and the
 * migration that first copies the config defaults filters by the same method so
 * the boundary is not something only the HTTP layer enforces.
 */
class OrganizationRole extends Model
{
    use HasFactory;

    /** The standing whose grants are never empty and whose name never changes. */
    public const KEY_OWNER = 'owner';

    protected $fillable = [
        'organization_id',
        'key',
        'name_ar',
        'name_en',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'organization_role_permissions')
            ->withTimestamps();
    }

    /** Every standing this company has, system-seeded and self-named alike. */
    public static function forOrganization(int|string $organizationId): array
    {
        /** @var list<self> $roles */
        $roles = static::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('is_system')
            ->orderBy('id')
            ->get()
            ->all();

        return $roles;
    }

    public static function lookup(int|string $organizationId, ?string $key): ?self
    {
        if ($key === null || $key === '') {
            return null;
        }

        return static::query()
            ->where('organization_id', $organizationId)
            ->where('key', $key)
            ->first();
    }

    public static function existsForKey(int|string $organizationId, ?string $key): bool
    {
        if ($key === null || $key === '') {
            return false;
        }

        // Compared as the column is collated (`utf8mb4_bin`), through the model
        // rather than a hand-written query, so the answer cannot disagree with the
        // row that `lookup()` later loads.
        return static::query()
            ->where('organization_id', $organizationId)
            ->where('key', $key)
            ->exists();
    }

    /**
     * The names this standing is worth, already filtered by scope.
     *
     * The filter is not decoration: writes refuse a platform name, but the table
     * can also be filled by a migration, a seed or a database console, and a
     * company standing must never reach a platform capability through any of them.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        $this->loadMissing('permissions');

        /** @var list<string> $names */
        $names = $this->permissions->pluck('name')->all();

        return array_values(array_filter(
            $names,
            static fn (string $name): bool => RbacScope::exercisableInsideOrganization($name)
                && OrganizationMembership::isCatalogued($name)
        ));
    }

    /**
     * Replace this standing's grants with exactly these names.
     *
     * Returns the names it refused rather than throwing: the caller is an admin
     * screen that has to say which of the forty-odd checkboxes it just cleared
     * were the ones the company door may not carry.
     *
     * @param  array<array-key, string>  $names
     * @return list<string> the refused names, empty when the write went through
     */
    public function replacePermissions(array $names): array
    {
        $refused = RbacScope::unacceptableInOrganization($names);

        $wanted = array_values(array_unique(array_filter(
            $names,
            static fn (string $name): bool => !in_array($name, $refused, true)
        )));

        $unknown = array_values(array_filter(
            $wanted,
            static fn (string $name): bool => !OrganizationMembership::isCatalogued($name)
        ));

        if ($unknown !== []) {
            // A typo is not a permission: attaching it would create nothing and
            // report success, which is how a standing ends up meaning something
            // other than what its editor asked for.
            return array_values(array_unique([...$refused, ...$unknown]));
        }

        $ids = DB::table('permissions')->whereIn('name', $wanted)->pluck('id');

        $this->permissions()->sync($ids->all());

        return $refused;
    }

    /**
     * Give a company the five standings the platform names, worth the config
     * defaults, unless it already has a standing under that key.
     *
     * Idempotent by key and by pair, because three things call it: an organization
     * being created, a console command repairing companies that predate the table,
     * and the migration that first fills it. A company that has already edited
     * «موظف» down must not have the config's copy written back over its own — the
     * config is the default, not the truth.
     *
     * @return list<self>
     */
    public static function provisionOrganization(int|string $organizationId): array
    {
        /** @var array<string, string> $standings */
        $standings = (array) config('rbac.membership_roles');

        /** @var array<string, list<string>> $matrix */
        $matrix = (array) config('rbac.membership_permissions');

        /** @var list<self> $roles */
        $roles = [];

        foreach ($standings as $key => $label) {
            $role = static::firstOrNew([
                'organization_id' => $organizationId,
                'key' => $key,
            ]);

            $fresh = !$role->exists;

            if ($fresh) {
                $role->name_ar = $label;
                $role->is_system = true;
                $role->save();
            }

            $roles[] = $role;

            if (!$fresh) {
                continue;
            }

            $names = array_values(array_filter(
                $matrix[$key] ?? [],
                static fn (string $name): bool => RbacScope::exercisableInsideOrganization($name)
                    && OrganizationMembership::isCatalogued($name)
            ));

            $ids = $names === [] ? [] : DB::table('permissions')->whereIn('name', $names)->pluck('id')->all();

            $role->permissions()->sync($ids);
        }

        return $roles;
    }

    /**
     * A key a company may give one of its own standings.
     *
     * Free text is the point of custom roles, so the guard is on shape and
     * collision rather than on a list: the column is 64 characters and compared
     * case-sensitively, and a key that differs from a system standing only by
     * capitalisation would render as one standing on every screen and read as two
     * to the database.
     *
     * @return array{0: bool, 1: string} [accepted, reason]
     */
    public static function validateKey(string $key): array
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $key) !== 1) {
            return [false, 'المفتاح يجب أن يبدأ بحرف لاتيني صغير ولا يتجاوز ٦٤ خانة، بدون مسافات'];
        }

        return [true, ''];
    }
}
