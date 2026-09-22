<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A login that comes from another party is recorded as its own row, and an
 * account is allowed to exist without a local password.
 *
 * Two changes, one decision behind them: a person can prove who they are
 * without this platform holding the proof.
 *
 *   * `external_identities` — one row per (provider, subject) a person has
 *     signed in with. It is a separate table rather than a `google_id` column
 *     on `users` because a person is not a Google: the same account can hold a
 *     Google identity today and another one later, and a column per provider is
 *     how a schema ends up with nine nullable one-use columns whose meaning is
 *     "whichever of these happened to be filled in".
 *   * `users.password` becomes nullable. Until now the column was NOT NULL with
 *     no default, so an account created from a Google assertion had to be given
 *     an invented password — a string nobody chose, which either works as a
 *     password (a back door) or does not (a lie in the column). NULL now means
 *     exactly what it says: this account has no local password, and the login
 *     form that asks for one is not its door. `AuthController::login()` already
 *     compares against it and is guarded for the null case.
 *
 * Nothing here says a Google identity was *checked against a government
 * register*. Google attests that it owns the mailbox and that the mailbox is
 * verified; the person's legal record on this platform is still the organization
 * document a reviewer reads, and this table stores only the assertion's own
 * subject and email — not the token, which is a credential and is never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            // The provider's own stable identifier for the account
            // (`sub` for Google). It is the only field safe to match a repeat
            // login against: an email address can be released and re-registered
            // by someone else, a subject number cannot.
            $table->string('provider_user_id', 191);
            // The address the provider asserted, and whether it said it had
            // verified it. Kept because a person may change the address at the
            // provider while the subject stays the same — the row then records
            // what was true when the link was made, and the login path always
            // re-reads the current claim rather than trusting this copy.
            $table->string('email', 255)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id'], 'external_identities_provider_subject_unique');
            $table->index('user_id');
        });

        /** @var list<string> $providers */
        $providers = array_values((array) config('hassal.auth.external_identity_providers'));
        abort_unless($providers !== [], 1, 'hassal.auth.external_identity_providers is empty; refusing to constrain the column to nothing.');

        $values = implode(',', array_map(
            static fn (string $provider): string => "'" . addslashes($provider) . "'",
            $providers
        ));

        // Case-sensitively, for the same reason the membership-role constraint
        // is: the column's collation would otherwise read `GOOGLE` as `google`
        // and a second row would then describe the same identity differently.
        DB::statement("ALTER TABLE `external_identities` ADD CONSTRAINT `external_identities_provider_is_known` CHECK (`provider` COLLATE utf8mb4_bin IN ({$values}))");

        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Accounts that were created through a provider have no password to
        // restore. Backfilling one would be inventing a credential nobody
        // chose, so the rollback makes them reachable again by giving them a
        // random, never-issued secret and leaves the operator to reset it
        // through the normal forgot-password path.
        DB::table('users')->whereNull('password')->update([
            'password' => bcrypt(bin2hex(random_bytes(16))),
        ]);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable(false)->default(null)->change();
        });

        DB::statement('ALTER TABLE `external_identities` DROP CHECK `external_identities_provider_is_known`');
        Schema::dropIfExists('external_identities');
    }
};
