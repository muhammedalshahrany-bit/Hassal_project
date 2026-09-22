<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public engagement on a listing: who is interested, and what they asked.
 *
 * Two deliberate omissions, both from the rule that the platform may only
 * report what it has actually observed:
 *
 *   * There is no rating, star or score here. A like is a record that one
 *     signed-in business pressed a button — a fact about that business — while
 *     a rating is a conclusion about the seller that this platform has never
 *     computed (see 2026_09_19_150000_make_organization_rating_unclaimed.php).
 *   * There is no anonymous comment. Every row names its author and their
 *     organization, because an unattributed public statement on a commercial
 *     listing cannot be moderated, cannot be trusted and cannot be acted on.
 *
 * A like is unique per (offer, user) so the counter cannot be farmed by one
 * person, and it is scoped to an organization as well because the same human
 * may act for two businesses and those are two different commercial signals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['offer_id', 'user_id', 'organization_id'], 'offer_likes_unique_per_actor');
            $table->index(['offer_id', 'created_at']);
        });

        Schema::create('offer_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();

            // The author's identity at the moment of writing. If they later
            // leave that organization the comment must still say where it came
            // from, so both ids are stored rather than resolved on read.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // A reply from the seller to a buyer's question is the only thread
            // depth this product needs; anything deeper is a chat, and HASSAL
            // does not have a chat layer.
            $table->foreignId('parent_id')->nullable()->constrained('offer_comments')->cascadeOnDelete();
            $table->boolean('is_seller_reply')->default(false);

            $table->text('body');

            // pending  = written, awaiting a reviewer (only when pre-moderation
            //            is on, or after enough reports to warrant a look)
            // published= visible in the listing thread
            // removed  = a reviewer took it down; the row and its moderation
            //            record stay, because "we never saw that" is not an
            //            answer a marketplace can give.
            $table->string('status', 20)->default('published');
            $table->unsignedInteger('report_count')->default(0);

            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->text('moderation_reason')->nullable();

            $table->timestamps();

            $table->index(['offer_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('offer_comment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // WHY it is objectionable, in the reporter's own classification —
            // a reviewer reads these and decides; the count alone never does.
            $table->string('reason', 40);
            $table->text('note')->nullable();
            $table->string('status', 20)->default('open'); // open | upheld | dismissed
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // One report per actor: an escalated comment is a signal about the
            // comment, not a contest of who clicks more.
            $table->unique(['offer_comment_id', 'user_id', 'organization_id'], 'comment_reports_unique_per_actor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_comment_reports');
        Schema::dropIfExists('offer_comments');
        Schema::dropIfExists('offer_likes');
    }
};
