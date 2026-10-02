<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own pin on a listing: "this one goes at the top of the market".
 *
 * Why a separate fact rather than a `is_featured` boolean
 *
 * A boolean records a decision and loses everything around it. This table has
 * already learned that lesson twice — `status` carried a meaning nobody could
 * re-derive, and `rating` claimed a calculation no code performed — so the pin
 * is stored as *when it happened and who did it* rather than as a flag:
 *
 *   * `featured_at` is the decision and its order at once. Null means the
 *     platform has not pinned this listing; a timestamp means it has, and the
 *     newest pin sits first. A second write moves it to the front rather than
 *     creating a duplicate, which is what "the admin changed their mind" looks
 *     like in data.
 *   * `featured_by` makes the decision attributable. The carousel is the one
 *     place where the platform's hand is on a buyer's first impression, and an
 *     unattributable boost is how a marketplace acquires a corruption story.
 *     `nullOnDelete` on purpose: an account leaving the platform's staff is not
 *     a reason to silently un-publish a promotion somebody else decided.
 *
 * What this column is *not*: it is not a ranking algorithm, not a paid
 * placement, and not a seller's own control. Nothing here computes relevance,
 * nobody buys a position, and `PUT /marketplace/offers/{id}/feature` is gated on
 * `offers.feature`, a platform capability that no business standing holds (see
 * `config/rbac.php`). A seller boosting themself is the seller's shelf, which
 * `status` and `PAUSED` already speak about.
 *
 * No index on `featured_at` alone beyond the composite below: the only read that
 * filters on it is the carousel, and that read always begins with "is this
 * still a listing the market may show at all", orders by the pin, and stops at a
 * ceiling. The composite serves the ordering after the filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->timestamp('featured_at')->nullable();
            $table->foreignId('featured_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['featured_at', 'status'], 'offers_featured_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropIndex('offers_featured_status_index');
            $table->dropForeign(['featured_by']);
            $table->dropColumn(['featured_at', 'featured_by']);
        });
    }
};
