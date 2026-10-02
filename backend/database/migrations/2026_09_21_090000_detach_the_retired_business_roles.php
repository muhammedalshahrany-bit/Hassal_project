<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Take the retired business roles off the accounts that still carry them.
 *
 * `SellerAdmin`, `SellerStaff`, `BuyerAdmin` and `BuyerStaff` were Spatie roles,
 * which is to say they attached authority to the *person*: one row made somebody
 * a seller administrator in every company they had ever joined, and it stayed
 * when they left. The authority itself now lives in
 * `config('rbac.membership_permissions')`, read from the standing recorded on
 * `organization_memberships.role` for the company a request acts for, so these
 * four rows grant nothing at all — `config/rbac.php` declares them empty on
 * purpose, so an old `assignRole()` resolves instead of throwing.
 *
 * Granting nothing is not the same as meaning nothing, and the console is where
 * that showed: an account listing its roles read "SellerAdmin", which an
 * operator could only interpret as a platform standing, and `UserManagementController`
 * now refuses anything that is not a platform role anyway. Leaving the rows
 * behind would keep a vocabulary in the data that no screen may write and no
 * check honours.
 *
 * The pairs are written to the log before they go, because this is the one kind
 * of cleanup that cannot be reversed from the schema: `down()` explains that
 * rather than pretending.
 */
return new class extends Migration
{
    private const RETIRED = ['SellerAdmin', 'SellerStaff', 'BuyerAdmin', 'BuyerStaff'];

    public function up(): void
    {
        // Only names the matrix still declares — and only with empty grants. A
        // role that somehow acquired permissions would be a different decision,
        // so the migration refuses to make it.
        $roles = DB::table('roles')
            ->whereIn('name', self::RETIRED)
            ->pluck('id', 'name');

        if ($roles->isEmpty()) {
            return;
        }

        $carried = DB::table('model_has_roles')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.model_type', 'App\Identity\Models\User')
            ->whereIn('model_has_roles.role_id', $roles->values()->all())
            ->select('users.id as user_id', 'users.name', 'model_has_roles.role_id')
            ->get();

        if ($carried->isNotEmpty()) {
            $names = $roles->flip()->all();

            logger()->info(
                'Detached the retired business roles; they granted nothing and the accounts keep their company standings. '
                . $carried->map(
                    static fn ($row) => "user#{$row->user_id} ({$row->name}) ← " . ($names[$row->role_id] ?? $row->role_id)
                )->implode('; ')
            );
        }

        DB::table('model_has_roles')
            ->where('model_type', 'App\Identity\Models\User')
            ->whereIn('role_id', $roles->values()->all())
            ->delete();

        // A role no account carries and no permission row references can now be
        // pruned by `RolesAndPermissionsSeeder`, which converges the matrix; it
        // leaves a role alone while `model_has_roles` still names it.
        // `role_has_permissions` is where Spatie keeps what a role grants. If a
        // retired row still holds anything there, the matrix and the database
        // disagree — which is a decision for whoever wrote it, not for a
        // migration that was told to remove assignments, not authorities.
        $held = DB::table('role_has_permissions')
            ->whereIn('role_id', $roles->values()->all())
            ->pluck('role_id')
            ->unique();

        if ($held->isNotEmpty()) {
            logger()->warning(
                'A retired business role still holds permission rows of its own, so they were left in place: role ids '
                . $held->implode(', ') . '. Clear them in config/rbac.php before removing either.'
            );
        }

        // A role the matrix never declared is a different problem from a role it
        // retired: this one grants real authority that no file describes, and the
        // converging seeder deliberately leaves it attached to whoever holds it.
        // Naming it is as far as a migration may go — deciding what an account
        // becomes is an operator's call, not an upgrade step's.
        $undeclared = DB::table('roles')
            ->whereNotIn('name', array_keys((array) config('rbac.roles')))
            ->pluck('name');

        if ($undeclared->isNotEmpty()) {
            logger()->warning(
                'roles holds names that config/rbac.php does not declare, so their grants are not described anywhere in the code: '
                . $undeclared->implode(', ') . '. The seeder leaves them attached to their accounts.'
            );
        }
    }

    public function down(): void
    {
        // Nothing to restore reliably: the assignments this removed carried no
        // meaning that survives the new model, and the list of who held which is
        // in the log rather than in the schema. Re-adding them would put a
        // vocabulary back that no check honours.
    }
};
