<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number', 50)->unique();
            $table->foreignId('deal_id')->constrained('deals')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->string('currency_code', 3)->default('YER');
            $table->decimal('exchange_rate_snapshot', 18, 6)->default(1.000000);
            $table->string('provider', 50); // BANK_TRANSFER, CASH_ON_DELIVERY, ESCROW_WALLET, ONLINE_GATEWAY
            $table->string('status', 30)->default('PENDING'); // PENDING, PROCESSING, SUCCEEDED, FAILED, REFUNDED
            $table->string('transaction_reference', 100)->nullable();
            $table->string('bank_receipt_url')->nullable();
            $table->json('payment_details')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['deal_id', 'status']);
            $table->index(['organization_id', 'status']);
            $table->index('transaction_reference');
        });

        Schema::create('escrows', function (Blueprint $table) {
            $table->id();
            $table->string('escrow_number', 50)->unique();
            $table->foreignId('deal_id')->unique()->constrained('deals')->restrictOnDelete();
            $table->foreignId('buyer_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('seller_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->decimal('gross_amount', 18, 4);
            $table->decimal('platform_fee_amount', 18, 4)->default(0.0000);
            $table->decimal('seller_net_amount', 18, 4);
            $table->string('currency_code', 3)->default('YER');
            $table->string('status', 30)->default('HELD'); // HELD, RELEASED, REFUNDED, PARTIALLY_REFUNDED
            $table->timestamp('funded_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->text('release_notes')->nullable();
            $table->timestamps();

            $table->index(['buyer_organization_id', 'status']);
            $table->index(['seller_organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escrows');
        Schema::dropIfExists('payments');
    }
};
