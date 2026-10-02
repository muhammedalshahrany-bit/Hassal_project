<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The consumer reaches the thread: attribution may name a person alone.
 *
 * `2026_09_19_180000` wrote these three tables when every actor on HASSAL was a
 * company, and stored that assumption in the schema — `organization_id` NOT NULL
 * with a cascade, and a uniqueness per (row, user, organization). §1 of the
 * product brief makes the consumer first-class, and a consumer has an account,
 * a verified phone and a name, but no commercial registration. This migration
 * changes what is recorded *about* an engagement row, not whether it is
 * recorded: every comment, like and report still names its author by
 * `user_id` — nothing here reopens the anonymous-graffiti door the original
 * migration closed, it closes the fiction that every human on a phone belongs
 * to a business.
 *
 * The one-per-actor uniqueness narrows with it: `(offer, user)` and
 * `(comment, user)`. That is not a loosening either. A NULL organization
 * repeats freely inside a MySQL unique index, so the old triple could not have
 * enforced anything about consumer rows, and a per-person rule is what the
 * feature is actually for — interest that cannot be farmed by one human,
 * reports that are a signal and not a poll. The old triple also let the same
 * person press "interested" once per company and inflate a public count twice
 * with one heartbeat; the count a buyer reads is now one per human, which is
 * the honest number the migration comment of 2026-09-19 said this table was
 * built to produce.
 *
 * `down()` is deliberately destructive of the consumer rows it would orphan:
 * a NOT NULL organization cannot hold a person without one, and inventing a
 * company to keep them would be the exact lie this migration removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── offer_likes ───────────────────────────────────────────────────────
        // The old per-actor unique is the only index MySQL can use for the
        // `offer_id` foreign key, so it cannot be dropped before a replacement
        // exists (error 1553). The order below is forced: dedupe, widen the new
        // unique that carries the same leftmost prefix, and only then remove
        // the old index the FK no longer needs.
        //
        // Two rows for one (offer, user) can exist on a live database — the old
        // index allowed them across two organizations — and they would block the
        // new unique. The keeper is the most recent press, because the freshest
        // signal of a person is what they mean now.
        DB::statement('
            DELETE offer_likes
            FROM offer_likes
            JOIN (
                SELECT offer_id, user_id, MAX(id) AS keep_id
                FROM offer_likes
                GROUP BY offer_id, user_id
            ) AS keeper
              ON keeper.offer_id = offer_likes.offer_id
             AND keeper.user_id = offer_likes.user_id
             AND keeper.keep_id <> offer_likes.id
        ');

        DB::statement('ALTER TABLE offer_likes MODIFY organization_id BIGINT UNSIGNED NULL');

        Schema::table('offer_likes', function (Blueprint $table) {
            $table->unique(['offer_id', 'user_id'], 'offer_likes_unique_per_person');
        });

        Schema::table('offer_likes', function (Blueprint $table) {
            $table->dropUnique('offer_likes_unique_per_actor');
        });

        // ── offer_comments ────────────────────────────────────────────────────
        // No uniqueness to rebuild: a thread is not deduplicated, and moderation
        // rows were already nullable on every column a reviewer fills later.
        DB::statement('ALTER TABLE offer_comments MODIFY organization_id BIGINT UNSIGNED NULL');

        // ── offer_comment_reports ─────────────────────────────────────────────
        DB::statement('
            DELETE offer_comment_reports
            FROM offer_comment_reports
            JOIN (
                SELECT offer_comment_id, user_id, MAX(id) AS keep_id
                FROM offer_comment_reports
                GROUP BY offer_comment_id, user_id
            ) AS keeper
              ON keeper.offer_comment_id = offer_comment_reports.offer_comment_id
             AND keeper.user_id = offer_comment_reports.user_id
             AND keeper.keep_id <> offer_comment_reports.id
        ');

        DB::statement('ALTER TABLE offer_comment_reports MODIFY organization_id BIGINT UNSIGNED NULL');

        Schema::table('offer_comment_reports', function (Blueprint $table) {
            $table->unique(['offer_comment_id', 'user_id'], 'comment_reports_unique_per_person');
        });

        Schema::table('offer_comment_reports', function (Blueprint $table) {
            $table->dropUnique('comment_reports_unique_per_actor');
        });
    }

    public function down(): void
    {
        Schema::table('offer_comment_reports', function (Blueprint $table) {
            $table->dropUnique('comment_reports_unique_per_person');
        });
        DB::statement('DELETE FROM offer_comment_reports WHERE organization_id IS NULL');
        DB::statement('ALTER TABLE offer_comment_reports MODIFY organization_id BIGINT UNSIGNED NOT NULL');
        Schema::table('offer_comment_reports', function (Blueprint $table) {
            $table->unique(['offer_comment_id', 'user_id', 'organization_id'], 'comment_reports_unique_per_actor');
        });

        // A consumer comment may have been reported by a company; rolling back
        // deletes the comment, so its reports must go first or the foreign key
        // refuses the whole statement.
        DB::statement('DELETE r FROM offer_comment_reports r JOIN offer_comments c ON r.offer_comment_id = c.id WHERE c.organization_id IS NULL');
        DB::statement('DELETE child FROM offer_comments child JOIN offer_comments parent ON child.parent_id = parent.id WHERE parent.organization_id IS NULL');
        DB::statement('DELETE FROM offer_comments WHERE organization_id IS NULL');
        DB::statement('ALTER TABLE offer_comments MODIFY organization_id BIGINT UNSIGNED NOT NULL');

        Schema::table('offer_likes', function (Blueprint $table) {
            $table->dropUnique('offer_likes_unique_per_person');
        });
        DB::statement('DELETE FROM offer_likes WHERE organization_id IS NULL');
        DB::statement('ALTER TABLE offer_likes MODIFY organization_id BIGINT UNSIGNED NOT NULL');
        Schema::table('offer_likes', function (Blueprint $table) {
            $table->unique(['offer_id', 'user_id', 'organization_id'], 'offer_likes_unique_per_actor');
        });
    }
};
