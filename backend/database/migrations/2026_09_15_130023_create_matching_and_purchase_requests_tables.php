<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demand_id')->constrained('demands')->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('buyer_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('seller_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->decimal('requested_quantity', 14, 2);
            $table->decimal('match_score', 5, 2)->default(0);
            $table->json('match_reasons')->nullable();
            $table->decimal('estimated_total', 18, 4)->default(0);
            $table->string('status', 30)->default('ACTIVE');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['demand_id', 'offer_id']);
            $table->index(['buyer_organization_id', 'status']);
            $table->index(['seller_organization_id', 'status']);
        });

        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('purchase_request_number', 50)->unique();
            $table->foreignId('demand_id')->nullable()->constrained('demands')->nullOnDelete();
            $table->foreignId('demand_match_id')->nullable()->constrained('demand_matches')->nullOnDelete();
            $table->foreignId('offer_id')->constrained('offers')->restrictOnDelete();
            $table->foreignId('buyer_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('seller_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('buyer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('requested_quantity', 14, 2);
            $table->string('unit', 50)->default('unit');
            $table->decimal('requested_price', 18, 4);
            $table->string('currency_code', 3)->default('YER');
            $table->string('delivery_preference', 30)->default('PICKUP');
            $table->string('status', 30)->default('REQUESTED');
            $table->text('notes')->nullable();
            $table->json('proposed_terms')->nullable();
            $table->timestamps();

            $table->index(['buyer_organization_id', 'status']);
            $table->index(['seller_organization_id', 'status']);
            $table->index(['offer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('demand_matches');
    }
};
