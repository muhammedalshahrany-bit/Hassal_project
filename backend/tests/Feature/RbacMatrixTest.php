<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * `config/rbac.php` declares that every permission it lists is enforced by the
 * backend. That is the claim this file tests, and it is the claim the platform
 * has to keep: a permission that is seeded, granted to a role, and checked by
 * nothing is not a control — it is a description of a control, and an admin
 * console will render a toggle for it.
 *
 * So the test reads the catalog and looks for each name in exactly the places
 * a check can live: the route files, where `permission:` and `role:` middleware
 * attach, and `app/`, where a controller or service can call `can()` or read
 * the requirement itself. Anything else is decoration.
 *
 * It runs in both directions. The reverse case — a `permission:` middleware
 * naming a capability the catalog never declared — is the quieter failure: the
 * route denies everybody including SuperAdmin, and the reason is not in any
 * list anybody maintains.
 */
class RbacMatrixTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    /**
     * @return array<string, string> permission name => where it was found
     */
    protected function enforcementMap(): array
    {
        $paths = [base_path('routes/api.php'), base_path('routes/web.php')];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        // The catalog itself and the seeder that applies it are declarations,
        // not enforcement, and this test file quotes names as evidence.
        $skip = [
            base_path('config/rbac.php'),
            base_path('database/seeders/RolesAndPermissionsSeeder.php'),
            __DIR__ . '/RbacMatrixTest.php',
        ];

        $haystack = '';
        foreach ($paths as $path) {
            if (in_array($path, $skip, true)) {
                continue;
            }
            $haystack .= file_get_contents($path) . "\n";
        }

        $map = [];
        foreach (config('rbac.permissions') as $permission) {
            if (str_contains($haystack, "'{$permission}'") || str_contains($haystack, "\"{$permission}\"") || str_contains($haystack, ":{$permission}")) {
                $map[$permission] = $permission;
            }
        }

        return $map;
    }

    public function test_every_permission_the_catalog_declares_is_enforced_somewhere(): void
    {
        $declared = config('rbac.permissions');

        $this->assertNotEmpty($declared, 'config/rbac.php did not load a permission list.');
        $this->assertSame($declared, array_unique($declared), 'The catalog declares a permission twice.');

        $enforced = $this->enforcementMap();
        $unenforced = array_values(array_diff($declared, array_keys($enforced)));

        $this->assertSame(
            [],
            $unenforced,
            "These permissions are granted to roles but checked by no route and no service: " . implode(', ', $unenforced)
            . '. Either enforce the capability or delete it from the catalog — a control that exists only in the '
            . 'database is a control an administrator believes they have.'
        );
    }

    public function test_no_route_checks_a_capability_the_catalog_never_declared(): void
    {
        $contents = file_get_contents(base_path('routes/api.php'));

        preg_match_all("/(?:role|permission|role_or_permission):([a-zA-Z_.,]+)/", $contents, $matches);

        $checked = [];
        foreach ($matches[1] as $group) {
            foreach (explode(',', $group) as $name) {
                if ($name !== '') {
                    $checked[] = $name;
                }
            }
        }

        $declaredPermissions = config('rbac.permissions');
        $declaredRoles = array_keys(config('rbac.roles'));

        $unknownPermissions = array_values(array_diff(
            array_filter($checked, fn ($n) => !in_array($n, $declaredRoles, true)),
            $declaredPermissions
        ));

        $this->assertSame(
            [],
            array_unique($unknownPermissions),
            'A route guards a capability that is not in the catalog, so no role can ever hold it.'
        );
    }

    public function test_the_seeded_matrix_matches_the_catalog(): void
    {
        $this->seedPlatformDefaults();

        $this->assertEquals(
            collect(config('rbac.permissions'))->sort()->values()->all(),
            \Spatie\Permission\Models\Permission::pluck('name')->sort()->values()->all(),
            'The database advertises access the code does not describe, or has lost access it grants.'
        );

        $this->assertEquals(
            collect(array_keys(config('rbac.roles')))->sort()->values()->all(),
            \Spatie\Permission\Models\Role::pluck('name')->sort()->values()->all(),
            'A role exists in one place and not the other.'
        );
    }

    public function test_business_roles_never_hold_a_platform_only_capability(): void
    {
        $roles = config('rbac.roles');

        // These four are the platform acting on somebody else's business: approving
        // a listing, verifying a company, editing another tenant's user, and
        // deciding a dispute. A counterparty that holds one is judging its own case.
        $platformOnly = ['offers.moderate', 'organizations.verify', 'users.manage', 'disputes.resolve'];

        foreach ($platformOnly as $permission) {
            $this->assertContains($permission, config('rbac.permissions'), "{$permission} must stay in the catalog.");

            foreach ($roles as $roleName => $grants) {
                if ($roleName === 'SuperAdmin' || $grants === '*') {
                    continue;
                }

                $this->assertNotContains(
                    $permission,
                    $grants,
                    "Role [{$roleName}] holds {$permission}, which is a platform responsibility."
                );
            }
        }
    }
}
