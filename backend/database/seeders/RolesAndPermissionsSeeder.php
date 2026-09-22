<?php

namespace Database\Seeders;

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

        foreach ($wantedPermissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => self::GUARD]);
        }

        foreach ($matrix['roles'] as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => self::GUARD]);
            $role->syncPermissions($rolePermissions === '*' ? $wantedPermissions : $rolePermissions);
        }

        $this->pruneStaleEntries($wantedPermissions, array_keys($matrix['roles']));

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
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
