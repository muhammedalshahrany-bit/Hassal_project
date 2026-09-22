<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the two price columns HASSAL cannot stand behind.
 *
 * `offers.market_reference_price` let a seller type any number as "the market
 * price", and `offers.discount_percentage` was then *derived* from it — so the
 * marketplace could sort a feed by "biggest discount" and show a strikethrough
 * price, both computed entirely from an unsourced input. A discount nobody can
 * trace is not information, it is a sales tactic the platform itself authored,
 * and §47 forbids the platform claiming a market price it has no source for.
 *
 * There is no provider, feed, or historical series behind either column, so
 * they go rather than wait for one. When a real price source is integrated it
 * arrives as its own table with a source and a timestamp — not as a free-text
 * field on the seller's listing.
 *
 * The seller's `unit_price` is untouched: that is the one price HASSAL knows
 * who set it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['market_reference_price', 'discount_percentage'] as $column) {
            if (Schema::hasColumn('offers', $column)) {
                DB::statement("ALTER TABLE `offers` DROP COLUMN `{$column}`");
            }
        }
    }

    public function down(): void
    {
        // Restoring the columns is possible; restoring the meaning is not.
        if (!Schema::hasColumn('offers', 'market_reference_price')) {
            DB::statement('ALTER TABLE `offers` ADD COLUMN `market_reference_price` DECIMAL(18,4) NULL AFTER `unit_price`');
        }

        if (!Schema::hasColumn('offers', 'discount_percentage')) {
            DB::statement('ALTER TABLE `offers` ADD COLUMN `discount_percentage` DECIMAL(5,2) NULL AFTER `market_reference_price`');
        }
    }
};
