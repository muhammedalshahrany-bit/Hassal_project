<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a seller's own name appears on a public listing.
 *
 * A business can have a real reason to publish a surplus and not advertise who
 * published it: a factory that supplies competitors' retail arms does not want
 * its shelf read as a signal of its stock position, and in Yemen's market that
 * reading has consequences beyond commercial embarrassment. Today the only way
 * to list anonymously is to file a false organization name, which destroys the
 * trust the listing exists to earn. So the choice has to be a recorded field.
 *
 * What the switch governs is public display, not the record. The offer always
 * keeps its real `seller_organization_id`; a purchase request still reaches the
 * named company, a deal is still between two named companies, and platform
 * moderation still reads the real name. Hiding a counterparty from someone
 * about to trade with it would be a fraud feature, not a privacy one.
 *
 * Default `true`: every listing published before this column exists was, and
 * remains, attributed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->boolean('show_seller_identity')->default(true)->index();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('show_seller_identity');
        });
    }
};
