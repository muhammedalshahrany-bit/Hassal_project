<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The catalogue asks for an Arabic name and treats the English one as
 * optional — `ProductCatalogController::storeProduct()` validates
 * `name_en => nullable`. The column disagreed: it was NOT NULL with no
 * default, so posting a product without an English name did not return a
 * 422 or a created row, it returned a 500 from MySQL strict mode
 * (`Field 'name_en' doesn't have a default value`).
 *
 * A food catalogue in Yemen is named in Arabic first. The column has to be
 * able to hold "no English name recorded", which is what the API already
 * promises, so the schema follows the contract rather than the other way
 * round. Note that `product_variants.name_en` stays as it is: the same
 * controller copies the Arabic name into the default variant it creates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('name_en')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rolling back to NOT NULL cannot invent a name, so each row without an
        // English name gets the Arabic one it already carries — the same
        // stand-in the controller uses for a variant, not a translation.
        DB::table('products')
            ->whereNull('name_en')
            ->update(['name_en' => DB::raw('name_ar')]);

        Schema::table('products', function (Blueprint $table) {
            $table->string('name_en')->nullable(false)->change();
        });
    }
};
