<?php

use App\Core\Rbac\RbacScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give every permission row the subject it is allowed to attach to.
 *
 * `App\Core\Rbac\RbacScope` reads that boundary from `config/rbac.php` and refuses
 * the wrong-direction write, but the check reached into config on every question
 * while the database — the thing an operator edits from a console, and the thing
 * a screen reads to draw its toggles — carried no answer at all. A rule that
 * lives in one place and is enforced from another drifts the moment the second
 * place is offline, so the same decision is now stamped on the row: `platform`
 * (an act upon the market or another business), `business` (a company acting on
 * its own trade), `both` (a name whose breadth is decided by the organization
 * context rather than by its name).
 *
 * The default is `business`, the restrictive side of the two mistakes available
 * here: a hand-created row nobody classified can be granted inside a company
 * that already speaks of it, and cannot quietly become an account-wide
 * capability that follows the person from business to business.
 *
 * `down()` drops the column. Nothing of record is lost, because the catalog in
 * config is what converged these values onto the rows in the first place.
 */
return new class extends Migration
{
    public function up(): void
    {
        /** @var string $table */
        $table = config('permission.table_names.permissions', 'permissions');
        $indexName = "{$table}_scope_index";

        Schema::table($table, static function (Blueprint $blueprint) use ($indexName) {
            $blueprint->string('scope', 16)->default(RbacScope::BUSINESS)->after('guard_name');
            $blueprint->index('scope', $indexName);
        });

        // Converge from the catalog, so a database that is migrated without being
        // re-seeded still holds the boundary the code enforces.
        /** @var array<string, list<string>> $scopes */
        $scopes = (array) config('rbac.permission_scopes');

        foreach ([RbacScope::PLATFORM, RbacScope::BOTH] as $scope) {
            $names = $scopes[$scope] ?? [];

            if ($names !== []) {
                DB::table($table)->whereIn('name', $names)->update(['scope' => $scope]);
            }
        }
    }

    public function down(): void
    {
        /** @var string $table */
        $table = config('permission.table_names.permissions', 'permissions');

        Schema::table($table, static function (Blueprint $blueprint) use ($table): void {
            $blueprint->dropIndex("{$table}_scope_index");
            $blueprint->dropColumn('scope');
        });
    }
};
