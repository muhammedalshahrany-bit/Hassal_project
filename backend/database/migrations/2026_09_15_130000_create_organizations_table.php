<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
             $table->engine('InnoDB');
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('type'); // buyer, seller, intermediary, admin
            $table->string('commercial_registration_number', 50)->unique();
            $table->string('vat_number', 50)->unique();
            $table->string('status')->default('pending_kyc'); // pending_kyc, verified, suspended, rejected
            $table->decimal('credit_limit', 14, 2)->default(0.00);
            $table->decimal('rating', 3, 2)->default(5.00);
            $table->timestamp('kyc_verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
