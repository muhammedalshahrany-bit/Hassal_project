<?php

namespace Database\Seeders;

use App\Core\Rbac\RbacScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Applies the RBAC matrix declared in config/rbac.php.
 *
 * This is the only place roles and permissions are written to the database.
 * It converges rather than merely adds: a permission or role removed from the
 * config disappears from the schema too, otherwise the database would keep
 * advertising access the code no longer describes. Removing a role that is
 * still assigned to a user is skipped and reported instead of cascading into
 * someone's account.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    private const GUARD = 'web';

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $matrix = config('rbac');

        if (! is_array($matrix) || empty($matrix['permissions']) || empty($matrix['roles'])) {
            throw new \RuntimeException('config/rbac.php is missing or incomplete; refusing to seed RBAC.');
        }

        $wantedPermissions = $matrix['permissions'];

        // The scope is converged before the rows, so a permission that is new to
        // the catalog is born carrying the boundary rather than sitting at the
        // column default until somebody notices. `assertScopesCover` is the
        // fail-fast the config cannot express on its own: a name listed under two
        // scopes has no boundary, and a name listed nowhere has not been decided.
        $this->assertScopesCover($matrix['permissions'], (array) ($matrix['permission_scopes'] ?? []));

        foreach ($wantedPermissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => self::GUARD]);

            $scope = RbacScope::scopeOf($name);

            if ($permission->scope !== $scope) {
                $permission->forceFill(['scope' => $scope])->save();
            }
        }

        foreach ($matrix['roles'] as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => self::GUARD]);
            $role->syncPermissions($rolePermissions === '*' ? $wantedPermissions : $rolePermissions);
        }

        $this->pruneStaleEntries($wantedPermissions, array_keys($matrix['roles']));

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Refuse to seed a matrix whose boundary has not actually been decided.
     *
     * `config/rbac.php` names three scopes and every permission belongs to one of
     * them, so three mistakes are possible and all three are silent: a name
     * listed under two scopes (the boundary is then a rumour, and the first list
     * consulted wins), a scoped name the catalog never declared (the row can never
     * exist, so the entry is decoration), and a catalogued name nobody classified.
     * The last one is the dangerous one, because `RbacScope::scopeOf()` answers it
     * `business` — a reasonable default at read time and an unreviewed decision at
     * seed time, which is the only moment anybody is in a position to make it.
     *
     * @param  array<int, string>  $permissions
     * @param  array<string, array<int, string>>  $scopes
     */
    private function assertScopesCover(array $permissions, array $scopes): void
    {
        if ($scopes === []) {
            throw new \RuntimeException('config/rbac.php declares permissions but no `permission_scopes`; refusing to seed an RBAC matrix whose platform/business boundary is undocumented.');
        }

        $declared = array_values(array_filter(
            [RbacScope::PLATFORM, RbacScope::BOTH, RbacScope::BUSINESS],
            static fn (string $scope): bool => ($scopes[$scope] ?? []) !== []
        ));

        if (count($declared) !== 3) {
            throw new \RuntimeException('config/rbac.php must name all three scopes — platform, both and business — and it is missing: ' . implode(', ', array_diff(['platform', 'both', 'business'], $declared)));
        }

        $unknown = array_values(array_diff(
            array_merge(...array_map(static fn (string $scope): array => $scopes[$scope], $declared)),
            $permissions
        ));

        if ($unknown !== []) {
            throw new \RuntimeException('config/rbac.php scopes names that are not in its permission catalog: ' . implode(', ', $unknown));
        }

        // A name under two scopes is a name with no boundary: `scopeOf()` would
        // answer whichever list it happens to consult first, and the row would
        // carry an answer that changed with the order of a lookup.
        foreach ($declared as $index => $scope) {
            for ($other = $index + 1; $other < count($declared); $other++) {
                $claimedTwice = array_values(array_intersect($scopes[$scope], $scopes[$declared[$other]]));

                if ($claimedTwice !== []) {
                    throw new \RuntimeException("config/rbac.php scopes the same name as both `{$scope}` and `{$declared[$other]}`: " . implode(', ', $claimedTwice));
                }
            }
        }

        $unclassified = array_values(array_diff(
            $permissions,
            array_merge(...array_map(static fn (string $scope): array => $scopes[$scope], $declared))
        ));

        if ($unclassified !== []) {
            throw new \RuntimeException('config/rbac.php declares permissions no scope names — every name must be `platform`, `both` or `business`, and silence is not a decision: ' . implode(', ', $unclassified));
        }
    }

    /**
     * @param  array<int, string> $wantedPermissions
     * @param  array<int, string> $wantedRoles
     */
    private function pruneStaleEntries(array $wantedPermissions, array $wantedRoles): void
    {
        $deadPermissions = Permission::where('guard_name', self::GUARD)
            ->whereNotIn('name', $wantedPermissions)
            ->pluck('id', 'name');

        $deadRoles = Role::where('guard_name', self::GUARD)
            ->whereNotIn('name', $wantedRoles)
            ->pluck('id', 'name');

        foreach ($deadRoles as $name => $id) {
            $inUse = DB::table('model_has_roles')->where('role_id', $id)->exists();

            if ($inUse) {
                $this->command?->warn("Role '{$name}' is no longer in config/rbac.php but is still assigned to at least one user. Left in place — retire it manually after moving those accounts.");

                continue;
            }

            DB::table('role_has_permissions')->where('role_id', $id)->delete();
            DB::table('roles')->where('id', $id)->delete();
            $this->command?->line("Removed stale role: {$name}");
        }

        foreach ($deadPermissions as $name => $id) {
            $inUse = DB::table('role_has_permissions')->where('permission_id', $id)->exists()
                || DB::table('model_has_permissions')->where('permission_id', $id)->exists();

            if ($inUse) {
                continue;
            }

            DB::table('permissions')->where('id', $id)->delete();
            $this->command?->line("Removed stale permission: {$name}");
        }
    }
}
