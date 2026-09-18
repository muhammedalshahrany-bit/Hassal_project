<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            $table->string('reason', 100); // QUALITY_DEFECT, QUANTITY_MISMATCH, WRONG_ITEM, DELIVERY_DELAY, TEMPERATURE_ABUSE, OTHER
            $table->text('description');
            $table->decimal('claimed_amount', 18, 4);
            $table->json('evidence_urls')->nullable();
            $table->string('status', 30)->default('OPEN'); // OPEN, UNDER_REVIEW, EVIDENCE_REQUIRED, RESOLVED_REFUND, RESOLVED_RELEASE, RESOLVED_PARTIAL, REJECTED
            $table->string('resolution_decision')->nullable();
            $table->decimal('refunded_amount', 18, 4)->default(0.0000);
            $table->text('resolution_notes')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['deal_id', 'status']);
            $table->index(['initiator_organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
