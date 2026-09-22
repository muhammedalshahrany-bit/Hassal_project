<?php

use App\Models\FeatureFlag;
use Illuminate\Database\Migrations\Migration;

/**
 * Switch the `api_keys` capability off, where an older install has it on.
 *
 * This is not a normal operator edit and it is not what a catalog sync does —
 * a sync never touches a stored value. It is a correction of a switch that was
 * turned on by an installer whose defaults have since changed underneath it, and
 * that promises something no code keeps: no route in this application
 * authenticates an API key, so every key issued under this switch opens
 * nothing. Leaving it on lets a business generate a credential, hand it to a
 * partner as a live integration, and have both parties debug a door that was
 * never installed.
 *
 * Issuing keys is therefore gated off (`POST /api-keys` answers
 * `FEATURE_DISABLED`); listing and revoking stay reachable so an install that
 * issued keys earlier can still see and delete them. Turning the capability on
 * again is a deliberate act for whoever writes the guard that accepts a key —
 * the admin console will let them, and `ApiKeyAuthenticationService::
 * acceptedByAnyRoute()` starts reporting true the moment a route carries the
 * `api.key` middleware.
 */
return new class extends Migration
{
    public function up(): void
    {
        FeatureFlag::query()
            ->where('key', 'api_keys')
            ->where('is_enabled', true)
            ->update([
                'is_enabled' => false,
                'description_ar' => 'البنية جاهزة؛ لم يُفعّل بعد أي مسار مصادقة بالمفتاح، ولا يُصدر مفتاح وهو كذلك',
            ]);

        // The flag service memoises for an hour, and an invisible change to a
        // flag that gates a route is a silent 503 nobody can explain.
        app(\App\Services\FeatureFlagService::class)->flushCache();
    }

    public function down(): void
    {
        // Not restored. Switching a false capability back on is not something a
        // rollback should do silently; an operator can turn it on from the
        // console if they disagree, and the console shows what is missing.
    }
};
