<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->string('rate_source', 100)->nullable()->after('exchange_rate_to_base');
            $table->timestamp('rate_updated_at')->nullable()->after('rate_source');
            $table->foreignId('rate_updated_by_user_id')->nullable()->after('rate_updated_at')->constrained('users')->nullOnDelete();
        });

        // Settings are scoped to either the platform or an organization.  The old global unique key
        // prevented organization overrides altogether.
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropUnique('system_settings_key_unique');
            $table->index(['organization_id', 'key'], 'system_settings_scope_key_index');
        });

        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropUnique('idempotency_keys_key_unique');
            $table->unique(['key', 'user_id', 'organization_id'], 'idempotency_keys_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropIndex('system_settings_scope_key_index');
            $table->unique('key');
        });

        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropUnique('idempotency_keys_scope_unique');
            $table->unique('key');
        });

        Schema::table('currencies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rate_updated_by_user_id');
            $table->dropColumn(['rate_source', 'rate_updated_at']);
        });
    }
};
