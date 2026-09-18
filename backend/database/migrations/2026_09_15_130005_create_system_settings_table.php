<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // string, integer, decimal, boolean, json, array
            $table->string('group', 50)->default('platform'); // platform, marketplace, financial, notifications, localization
            $table->string('description_ar')->nullable();
            $table->string('description_en')->nullable();
            $table->text('default_value')->nullable();
            $table->boolean('is_editable')->default(true);
            $table->boolean('is_public')->default(false);
            $table->json('validation_rules')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->timestamps();

            $table->index(['group', 'is_public']);
            $table->index(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
