<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demands', function (Blueprint $table) {
            $table->id();
            $table->string('demand_number', 50)->unique();
            $table->foreignId('buyer_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('buyer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->text('description')->nullable();

            $table->decimal('target_quantity', 14, 2);
            $table->string('unit', 50)->default('carton');
            $table->decimal('target_unit_price', 18, 4)->nullable();
            $table->string('currency_code', 3)->default('YER');

            $table->date('target_delivery_date')->nullable();
            $table->string('delivery_city', 100);
            $table->decimal('delivery_latitude', 10, 7)->nullable();
            $table->decimal('delivery_longitude', 10, 7)->nullable();
            $table->text('special_requirements')->nullable();

            $table->string('status', 30)->default('OPEN'); // OPEN, QUOTED, ACCEPTED, CLOSED, EXPIRED
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['buyer_organization_id', 'status']);
            $table->index(['category_id', 'status']);
            $table->index(['delivery_city', 'status']);
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_number', 50)->unique();
            $table->foreignId('demand_id')->constrained('demands')->cascadeOnDelete();
            $table->foreignId('seller_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('seller_user_id')->constrained('users')->cascadeOnDelete();

            $table->decimal('quoted_quantity', 14, 2);
            $table->decimal('unit_price', 18, 4);
            $table->string('currency_code', 3)->default('YER');
            $table->decimal('total_amount', 18, 4);
            $table->unsignedInteger('validity_days')->default(7);
            $table->text('notes')->nullable();

            $table->string('status', 30)->default('SUBMITTED'); // SUBMITTED, ACCEPTED, REJECTED, EXPIRED
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('converted_deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->timestamps();

            $table->index(['demand_id', 'status']);
            $table->index(['seller_organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('demands');
    }
};
