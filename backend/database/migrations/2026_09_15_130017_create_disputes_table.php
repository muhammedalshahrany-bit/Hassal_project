<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->string('dispute_number', 50)->unique();
            $table->foreignId('deal_id')->constrained('deals')->restrictOnDelete();
            $table->foreignId('initiator_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('initiator_user_id')->constrained('users')->restrictOnDelete();
            // One of `App\Trading\Models\Dispute::REASONS`, validated on the way in.
            $table->string('reason', 100);
            $table->text('description');
            // What the filer says is at stake, in the deal's currency. A claim and
            // not a movement: the platform reads this number, it never pays it.
            $table->decimal('claimed_amount', 18, 4);
            $table->json('evidence_urls')->nullable();
            // One column carries the outcome. It used to have a twin
            // (`resolution_decision`) written with the same value in the same
            // statement, which is two places to disagree and no extra meaning;
            // and a `refunded_amount`, which described money this platform does
            // not hold and therefore cannot return.
            $table->string('status', 30)->default('OPEN');
            $table->text('resolution_notes')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['deal_id', 'status']);
            $table->index(['initiator_organization_id', 'status']);
        });

        // The vocabulary comes from the model, so a state the machine does not
        // know can never be written by hand into the table.
        $values = implode(',', array_map(
            static fn (string $status): string => "'" . addslashes($status) . "'",
            \App\Trading\Models\Dispute::STATUSES
        ));

        DB::statement("ALTER TABLE `disputes` ADD CONSTRAINT `disputes_status_is_canonical` CHECK (`status` COLLATE utf8mb4_bin IN ({$values}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
