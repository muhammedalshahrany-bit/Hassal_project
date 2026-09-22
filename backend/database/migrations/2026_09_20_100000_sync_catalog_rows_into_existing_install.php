<?php

use App\Support\CatalogSync;
use Illuminate\Database\Migrations\Migration;

/**
 * Give an existing installation the catalog rows added after it was installed.
 *
 * The tables are already there — this is not a schema change. It inserts the
 * `system_settings` and `feature_flags` rows the current catalogs declare and
 * this database has never heard of, so that a capability released after the
 * install becomes visible and switchable instead of failing closed forever.
 *
 * Rows that exist are not touched: an operator's chosen value outranks a
 * catalog default. See {@see CatalogSync} for why the alternative (re-seeding
 * defaults on every deploy) is the destructive one.
 */
return new class extends Migration
{
    public function up(): void
    {
        CatalogSync::run();
    }

    public function down(): void
    {
        // Deliberately empty. This migration cannot tell a row it inserted from
        // a row an operator created later with the same key, and deleting by
        // catalog key would remove a switch somebody may have configured and
        // the settings history attached to it.
    }
};
