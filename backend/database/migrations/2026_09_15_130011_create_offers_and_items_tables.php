<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();

            // Opportunity Reason
            $table->string('opportunity_reason', 50); // SURPLUS_STOCK, SLOW_MOVING, SEASONAL_STOCK, EXCESS_INVENTORY, LIQUIDATION, BRANCH_CLOSURE, ASSORTMENT_CHANGE, LOW_LOCAL_DEMAND
            $table->text('opportunity_reason_notes')->nullable();

            // Lifecycle Status
            $table->string('status', 30)->default('DRAFT'); // DRAFT, PENDING_REVIEW, ACTIVE, PAUSED, FULLY_RESERVED, SOLD_OUT, EXPIRED, CANCELLED, ARCHIVED

            // Quantities
            $table->decimal('min_order_quantity', 14, 2)->default(1.00);
            $table->decimal('max_order_quantity', 14, 2)->nullable();
            $table->decimal('total_quantity', 14, 2);
            $table->decimal('available_quantity', 14, 2);
            $table->decimal('reserved_quantity', 14, 2)->default(0.00);
            $table->decimal('sold_quantity', 14, 2)->default(0.00);
            $table->string('unit', 50)->default('carton');

            // Pricing & Currency
            $table->decimal('unit_price', 18, 4);
            $table->decimal('market_reference_price', 18, 4)->nullable();
            $table->decimal('discount_percentage', 5, 2)->nullable();
            $table->string('currency_code', 3)->default('YER');

            // Logistics & Dates
            $table->date('production_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('storage_condition', 50)->default('ambient');
            $table->string('delivery_terms', 50)->default('BOTH'); // BUYER_PICKUP, SELLER_DELIVERY, BOTH
            $table->unsignedInteger('inspection_window_hours')->default(48);

            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Indexes for high-performance marketplace filtering
            $table->index(['status', 'seller_organization_id']);
            $table->index(['status', 'opportunity_reason']);
            $table->index(['status', 'currency_code', 'unit_price']);
            $table->index(['warehouse_id', 'status']);
            $table->index('expires_at');
        });

        Schema::create('offer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('quantity', 14, 2);
            $table->decimal('unit_price', 18, 4);
            $table->string('currency_code', 3)->default('YER');
            $table->timestamps();

            $table->index(['offer_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_items');
        Schema::dropIfExists('offers');
    }
};
