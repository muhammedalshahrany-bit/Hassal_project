<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `webhooks.secret` is the HMAC key a partner uses to prove a delivery came
 * from HASSAL. It was stored as plain text, so a database backup, a read
 * replica or a SQL dump handed the holder the ability to forge signed events
 * to every registered endpoint — the one thing the signature exists to stop.
 *
 * The model now casts the column to `encrypted`, which stores Laravel's
 * base64 envelope rather than the raw key. That envelope is far longer than
 * the 70-character `whsec_…` value it replaces, so the column has to grow.
 *
 * The table is empty in every environment where this has been checked
 * (`select count(*) from webhooks` = 0), so there are no plain-text secrets to
 * re-encrypt; on a database that does hold rows, run the note in
 * SECURITY.md first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->string('secret', 512)->change();
        });
    }

    public function down(): void
    {
        // Only safe while the column holds nothing longer than 128 characters,
        // i.e. before any encrypted secret has been written.
        Schema::table('webhooks', function (Blueprint $table) {
            $table->string('secret', 128)->change();
        });
    }
};
