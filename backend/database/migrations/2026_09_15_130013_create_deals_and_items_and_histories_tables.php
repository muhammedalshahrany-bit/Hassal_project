<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->string('deal_number', 50)->unique();
            $table->foreignId('offer_id')->constrained('offers')->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->foreignId('buyer_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('seller_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('buyer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            // Deal Quantities & Currency
            $table->decimal('quantity', 14, 2);
            $table->decimal('unit_price', 18, 4);
            $table->string('currency_code', 3)->default('YER');
            $table->string('transaction_currency', 3)->default('YER');
            $table->string('base_currency', 3)->default('YER');
            $table->decimal('exchange_rate', 18, 6)->default(1.000000);
            $table->decimal('exchange_rate_snapshot', 18, 6)->default(1.000000);
            $table->string('rate_source', 50)->default('MANUAL_ADMIN_RATE');
            $table->timestamp('rate_timestamp')->nullable();

            // Pricing Snapshot (Immutable after deal creation)
            $table->decimal('base_amount', 18, 4);
            $table->decimal('discount_amount', 18, 4)->default(0.0000);
            $table->decimal('tax_rate_snapshot', 6, 4)->default(0.1500);
            $table->decimal('tax_amount', 18, 4)->default(0.0000);
            $table->decimal('platform_fee_rate_snapshot', 6, 4)->default(0.0250);
            $table->decimal('platform_fee_amount', 18, 4)->default(0.0000);
            $table->decimal('delivery_fee_amount', 18, 4)->default(0.0000);
            $table->decimal('buyer_total_amount', 18, 4);
            $table->decimal('seller_net_amount', 18, 4);

            // Lifecycle Status
            $table->string('status', 40)->default('PENDING_SELLER_APPROVAL'); 
            // DRAFT, PENDING_SELLER_APPROVAL, ACCEPTED, PENDING_PAYMENT, ESCROW_FUNDED, PROCESSING, READY_FOR_PICKUP, DISPATCHED, DELIVERED, INSPECTION, COMPLETED, SETTLED, CANCELLED, DISPUTED, RESOLVED, REFUNDED

            // Logistics & Inspection
            $table->string('delivery_method', 50)->default('PICKUP'); // PICKUP, SELLER_DELIVERY, THIRD_PARTY_CARRIER
            $table->string('delivery_address', 255)->nullable();
            $table->unsignedInteger('inspection_window_hours')->default(48);
            $table->timestamp('inspection_deadline_at')->nullable();

            // Lifecycle Timestamps
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Indexes
            $table->index(['buyer_organization_id', 'status']);
            $table->index(['seller_organization_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('offer_id');
        });

        Schema::create('deal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('snapshot_product_name');
            $table->string('snapshot_sku', 100);
            $table->string('snapshot_unit_name', 50);
            $table->decimal('quantity', 14, 2);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('subtotal_amount', 18, 4);
            $table->timestamps();

            $table->index('deal_id');
        });

        Schema::create('deal_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->string('from_status', 40);
            $table->string('to_status', 40);
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['deal_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_status_histories');
        Schema::dropIfExists('deal_items');
        Schema::dropIfExists('deals');
    }
};
