<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A rating is a conclusion about how a business has behaved. This platform has
 * never computed one.
 *
 * `organizations.rating` was created as `decimal(3,2)` with a default of 5.00,
 * and no line of application code has ever written the column — not the deal
 * flow, not the dispute outcome, not the admin console. So every business on
 * the marketplace has been displaying a perfect score that no evidence
 * supports, next to offers and demands where a buyer is choosing whom to trust.
 * `OfferResource` even carries the comment "null until completed deals produce
 * a real score" while the column made that impossible.
 *
 * The default is what created the claim, so the default is what goes. A null
 * now means what it always should have meant: nothing has been recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->decimal('rating', 3, 2)->nullable()->default(null)->change();
        });

        // Only the default value is erased. If an installation did record a
        // figure by hand, that is a decision someone made and it stays.
        DB::table('organizations')->where('rating', 5.00)->update(['rating' => null]);
    }

    public function down(): void
    {
        DB::table('organizations')->whereNull('rating')->update(['rating' => 5.00]);

        Schema::table('organizations', function (Blueprint $table) {
            $table->decimal('rating', 3, 2)->nullable(false)->default(5.00)->change();
        });
    }
};
