<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('key_id', 64)->unique(); // Public key prefix e.g., 'has_live_...'
            $table->string('key_secret_hash', 64); // SHA-256 hash of the secret
            $table->json('scopes'); // ['inventory:read', 'inventory:write', 'deals:read', 'orders:write', 'webhooks:manage']
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE, REVOKED, EXPIRED
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('key_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
