<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reservation_number', 50)->unique();
            $table->foreignId('offer_id')->constrained('offers')->restrictOnDelete();
            $table->foreignId('buyer_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('buyer_user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('quantity', 14, 2);
            $table->decimal('unit_price', 18, 4);
            $table->string('currency_code', 3)->default('YER');
            $table->decimal('total_amount', 18, 4);
            $table->string('delivery_preference', 50)->default('PICKUP'); // PICKUP, DELIVERY
            $table->string('status', 30)->default('ACTIVE'); // ACTIVE, CONVERTED_TO_DEAL, EXPIRED, CANCELLED
            $table->timestamp('expires_at');
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['offer_id', 'status']);
            $table->index(['buyer_organization_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
