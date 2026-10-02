<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Display and behaviour choices that belong to a person, not to a deployment.
 *
 * Why it is stored rather than kept on the handset: this product is one system
 * read from a phone and a browser, and the switch this exists for — hide my
 * organization's name on screen — is exactly the kind of choice that has to
 * hold on both. A preference kept in `SharedPreferences` is a preference that
 * reverts the moment the person signs in somewhere else, and the web client
 * has no such file to read.
 *
 * It is a JSON column on `users` rather than a key/value table because the
 * vocabulary is closed and small (`app/Support/PreferenceCatalog.php`) and is
 * always read whole. A row per key would buy nothing: no query here selects
 * one preference, and no preference is shared between accounts.
 *
 * Nullable rather than defaulted to `'{}'` in the column: `null` is "this
 * account has never been asked", which the API answers with the catalog's
 * defaults. Those are the same behaviour, but the difference survives in the
 * database, and it is what lets an operator tell "chose the default" from
 * "has not chosen yet".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('preferences')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
