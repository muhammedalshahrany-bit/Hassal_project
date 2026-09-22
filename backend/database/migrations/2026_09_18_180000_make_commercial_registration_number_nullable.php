<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A commercial registration number is a claim about a legal record, so the
 * column has to be able to hold "this business has not given us one".
 *
 * It used to be NOT NULL + UNIQUE. That constraint is what produced the
 * placeholder: registration code invented `PENDING-<uuid>` for every applicant
 * who left the field blank, and the marketplace then rendered those invented
 * strings next to offers as if a ministry had issued them. A column that cannot
 * be empty is a column that forces the platform to lie.
 *
 * MySQL permits unlimited NULLs in a unique index, so the uniqueness rule that
 * stops two businesses sharing one registration survives this change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('commercial_registration_number', 50)->nullable()->change();
        });

        // Retire the placeholders. They are not this platform's to keep, and
        // "no registration recorded" is the truthful state for those rows.
        DB::table('organizations')
            ->where('commercial_registration_number', 'like', 'PENDING-%')
            ->update(['commercial_registration_number' => null]);
    }

    public function down(): void
    {
        // Restoring NOT NULL would require inventing a value again, which is
        // the thing this migration removed. Give each row a distinct stand-in
        // (the column is unique, so one shared string would fail) so the
        // rollback can run; it is still not a real registration number.
        DB::table('organizations')
            ->whereNull('commercial_registration_number')
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $id) => DB::table('organizations')
                ->where('id', $id)
                ->update(['commercial_registration_number' => 'REVIEW-' . $id]));

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('commercial_registration_number', 50)->nullable(false)->change();
        });
    }
};
