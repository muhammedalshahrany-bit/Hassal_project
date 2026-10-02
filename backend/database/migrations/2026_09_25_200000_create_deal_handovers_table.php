<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The handover code: the piece that makes a delivery an observed fact.
 *
 * Before this table, "the goods arrived" was a button somebody pressed. The
 * deal moved to `DELIVERED`, then to `COMPLETED`, and the record held nothing
 * but the word of whoever had the app open. In a market where the seller and the
 * buyer are two organisations that will disagree about whether eighteen crates
 * left a warehouse, a status with no evidence behind it is a claim the platform
 * is not entitled to make.
 *
 * So the order carries one code, printed as a QR and typed as plain characters,
 * that only exists for that one order:
 *
 *  - it is issued when the seller accepts the order, which is the moment the
 *    goods become owed to a named buyer;
 *  - it is spent once, by whoever physically hands the goods over, and the row
 *    keeps who and when;
 *  - it dies with the order if that order is cancelled.
 *
 * And the buyer cannot close the deal — cannot mark the goods received and
 * cannot move the stock from "held for this order" to "sold" — until the code
 * has been spent. That single dependency is why the table exists: it turns
 * `COMPLETED` from an opinion into a fact two parties watched happen.
 *
 * The code is a *handover* secret, not a credential. It cannot sign anybody in,
 * it reveals nothing about an order the scanner is not already party to, and it
 * is worthless after one use. It is therefore stored plainly: hashing a value
 * whose only property is "hard to guess and good for one scan" buys nothing and
 * costs the ability to look a lost code up by its characters.
 *
 * Comparison is `utf8mb4_bin` on purpose. The alphabet drops the characters that
 * blur together on a printed label (0/O, 1/I), but a code read off a screen at a
 * loading bay is still read by a person, and a case-insensitive match against a
 * uniqueness rule is how two orders end up sharing a "unique" code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->unique()->constrained('deals')->restrictOnDelete();

            $table->string('code', 24)->collation('utf8mb4_bin')->unique();

            $table->string('status', 20)->collation('utf8mb4_bin')->default('ACTIVE');
            // ACTIVE, USED, INVALIDATED

            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by_organization_id')->nullable()->constrained('organizations')->nullOnDelete();

            $table->timestamp('issued_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'issued_at']);
        });

        DB::statement("ALTER TABLE `deal_handovers` ADD CONSTRAINT `deal_handovers_status_is_canonical` CHECK (`status` COLLATE utf8mb4_bin IN ('ACTIVE', 'USED', 'INVALIDATED'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `deal_handovers` DROP CHECK `deal_handovers_status_is_canonical`');
        Schema::dropIfExists('deal_handovers');
    }
};
