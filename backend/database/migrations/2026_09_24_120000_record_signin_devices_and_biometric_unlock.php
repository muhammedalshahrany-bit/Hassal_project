<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The phone becomes a known place a person signs in from.
 *
 * A bearer token is a fact about a session, not about the machine holding it, so
 * until now a deployment could not answer the two questions a person actually
 * asks: "which of my devices is still signed in?" and "why does this one open
 * without a password?".
 *
 *   * `device_key` is generated and kept by the client — a UUID on the phone, a
 *     stable id in the browser. It is not a secret and not a credential: it is a
 *     label the server stores rows against. Nothing here trusts it for access;
 *     every route below sits behind `auth:sanctum` and filters on `user_id`.
 *   * `token_id` is the Sanctum row this device's current session was issued
 *     under. Revoking the device deletes that token in the same request, which
 *     is the whole point of the list: a person who removes their old phone from
 *     the account should not have to hunt for the bearer credential that phone
 *     still holds. It is re-pointed on every sign-in from that device, because a
 *     sign-in mints a new token.
 *   * `biometric_unlock` records what the device says it does — a fingerprint or
 *     face opens *this app on this phone* without typing the password. It is
 *     bookkeeping and a warning label, never an authentication path: the server
 *     never accepts a biometric assertion, and the credential that reaches it is
 *     the same Sanctum token a password login would have received. The value of
 *     storing it is that the web account screen can say "هذا الهاتف يفتح بالبصمة"
 *     and let the person revoke the device that does.
 *
 * `users.status` and token expiry stay the real controls. A revoked device loses
 * its token in the same request that deletes its row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_key', 120);
            $table->string('name', 120);
            $table->string('platform', 20);
            $table->unsignedBigInteger('token_id')->nullable();
            $table->boolean('biometric_unlock')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_key'], 'user_devices_user_key_unique');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
