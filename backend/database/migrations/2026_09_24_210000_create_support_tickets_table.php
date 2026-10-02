<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support: a person telling the platform that something is wrong, and the
 * platform's answer.
 *
 * This table did not exist because nothing needed it until now. The rows that
 * look like it are not it: `offer_comment_reports` is a business flagging
 * another business's *words* for a moderator, `disputes` is two companies
 * disagreeing about a *deal*, and `audit_logs` is the platform recording what
 * it did. None of them can hold "the shelf screen will not save my price" — a
 * report about the product itself, filed by whoever is standing in front of it.
 *
 * Why the columns are these and not more:
 *
 *   * `reference` is shown to the filer and quoted back by them. A ticket you
 *     cannot name is a ticket you cannot follow up on, and a phone number is a
 *     bad thing to make someone read out over the road. Generated in the
 *     controller from the row's id, so it is stable and never collides.
 *   * `user_id` and `organization_id` are both stored. Membership ends; the
 *     report does not. Resolving the company on read would rewrite history for
 *     whoever inherited the row.
 *   * `category` is a fixed vocabulary the platform chooses, because it is what
 *     makes a queue sortable and what turns "there are complaints" into "half of
 *     them are one broken screen". The filer's own words live in `message`,
 *     which is what actually gets read.
 *   * `screen` and `context` are the address and the surroundings: which route
 *     the person was on, which build, which platform. They are answers to the
 *     question a support agent asks first, and they arrive from the client that
 *     knows them — which is also why the controller accepts a whitelist of keys
 *     rather than a blob it will later have to trust.
 *   * `status`, `reply`, `answered_by`, `answered_at` are what make "we looked"
 *     a provable thing. `dismissed` exists beside `resolved` deliberately: a
 *     report that turns out to be working-as-intended is answered, not deleted,
 *     and the difference is the only record the platform has of what it decided
 *     not to change.
 *
 * There is no attachment column, and that is not an oversight. A photograph of
 * a bug is uploaded through `UploadQuota`'s storage only if it can be held
 * against a size ceiling and scanned before it is served back; neither exists
 * here yet, so a field promising paper would be a field promising a file nobody
 * can retrieve. Until then, `message` carries the description.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            // Nullable, and only because the number is minted from the row's own
            // id: the insert has to happen before the reference can exist, so the
            // two writes share one transaction in `SupportController::store()`.
            // A `null` here therefore means a rolled-back insert rather than a
            // ticket anyone can read — which is also why it is unique rather than
            // a primary key the API would leak.
            $table->string('reference', 20)->nullable()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();

            $table->string('category', 40);
            $table->text('message');

            // Where it happened, and what the client could see about itself.
            $table->string('screen', 80)->nullable();
            $table->json('context')->nullable();

            $table->string('status', 20)->default('open'); // open | in_review | resolved | dismissed
            $table->text('reply')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();

            $table->timestamps();

            // The queue is read in "newest first, by status", and a person's own
            // list is read the same way for their rows alone.
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
