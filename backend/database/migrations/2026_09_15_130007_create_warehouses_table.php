<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50)->nullable();
            $table->string('city', 100);
            $table->string('district', 100)->nullable();
            $table->string('address_line', 255);
            $table->decimal('latitude', 10, 7)->default(24.7136000);
            $table->decimal('longitude', 10, 7)->default(46.6753000);
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
            $table->index(['city', 'is_active']);
            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
