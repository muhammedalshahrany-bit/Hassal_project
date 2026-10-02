<?php

namespace App\Console\Commands;

use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationMembership;
use App\Organizations\Models\OrganizationRole;
use App\Core\Rbac\RbacScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give every company the standings it staffs from, and report what should not exist.
 *
 * Three ways a company ends up without rows in `organization_roles`: it predates
 * the table and the migration that fills it did not run, it was created by a
 * seeder or an import that went straight to `OrganizationMembership::create()`, or
 * it was restored from a dump of the memberships table alone. The read path covers
 * for all three — a standing with no row is priced at the platform's default, which
 * is the right answer and the invisible one — so the symptom never appears. What
 * does appear is an owner opening «أدوار المنشأة», finding nothing to edit, and
 * being told by the screen that the company has no roles.
 *
 * This command also *reports* what it cannot fix, and reporting is the half that
 * makes it safe to run against production: a membership whose role key resolves to
 * no row and is not one of the platform's five names is a person standing at a
 * company that nobody can appoint or remove them from, since the validator now
 * accepts only keys the organization has. Deleting such rows would be deleting a
 * person's access as a side effect of a sync, so it is printed instead.
 */
class ProvisionOrganizationRoles extends Command
{
    protected $signature = 'rbac:provision-organization-roles
                            {--organization= : Provision one company by id, and check one company only}
                            {--reset-defaults : Re-apply the config grants to system standings that hold nothing (never to a standing that was edited)}';

    protected $description = 'Create the platform standings for companies missing them and report role keys no longer staffable';

    public function handle(): int
    {
        $only = $this->option('organization');

        $organizations = $only === null
            ? Organization::query()->orderBy('id')->get()
            : Organization::whereKey((int) $only)->get();

        if ($organizations->isEmpty()) {
            $this->error('No organization matched.');

            return self::FAILURE;
        }

        $provisioned = 0;
        $untouched = 0;
        $orphans = [];

        foreach ($organizations as $organization) {
            $existing = OrganizationRole::where('organization_id', $organization->id)->count();

            if ($existing > 0 && !$this->option('reset-defaults')) {
                $untouched++;
            } else {
                $roles = OrganizationRole::provisionOrganization($organization->id);

                if ($existing === 0) {
                    $provisioned++;
                    $this->line("Provisioned {$organization->id} ({$organization->name_ar}): " . count($roles) . ' standings');
                } else {
                    $untouched++;
                }
            }

            $keys = OrganizationRole::where('organization_id', $organization->id)->pluck('key')->all();

            /** @var list<string> $staffable */
            $staffable = array_merge($keys, OrganizationMembership::roleNames());

            $stray = DB::table('organization_memberships')
                ->where('organization_id', $organization->id)
                ->whereNotIn('role', $staffable)
                ->distinct()
                ->pluck('role')
                ->all();

            foreach ($stray as $role) {
                $orphans[] = "{$organization->id}: {$role}";
            }
        }

        $this->newLine();
        $this->info("Provisioned: {$provisioned} · already had standings: {$untouched} · companies checked: " . count($organizations));

        if ($orphans !== []) {
            $this->warn('Memberships whose standing no company row and no platform name describes:');

            foreach ($orphans as $orphan) {
                $this->line('  ' . $orphan);
            }

            $this->line('  Move those members onto a real standing; the validator will refuse to re-save them as they are.');
        }

        // The one leak worth printing on every run rather than only when somebody
        // asks: the company matrix is supposed to hold nothing the platform kept
        // for itself. The read path refuses such a row whatever it says, so this is
        // a report of a row that does nothing — and of whoever wrote it.
        $leaks = DB::table('organization_role_permissions')
            ->join('permissions', 'permissions.id', '=', 'organization_role_permissions.permission_id')
            ->where('permissions.scope', RbacScope::PLATFORM)
            ->select('organization_role_id', 'permissions.name')
            ->get();

        foreach ($leaks as $leak) {
            $this->warn("Platform capability on a company standing (ignored by the read path): role #{$leak->organization_role_id} — {$leak->name}");
        }

        return self::SUCCESS;
    }
}
