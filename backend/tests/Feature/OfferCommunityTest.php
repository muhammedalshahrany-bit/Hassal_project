<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\OfferComment;
use App\Models\OfferCommentReport;
use App\Models\OfferLike;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * Interest, questions and reports on a listing — and who may rule on them.
 *
 * The feature exists to answer a buyer's question in the only honest way
 * available: an attributed statement by a named business, on a named listing,
 * with the selling company's own answers marked as such. That design is only
 * true if the backend enforces it, so these tests are about four rules rather
 * than about pixels:
 *
 *   1. A count is derived from rows. Two presses by the same actor are one row,
 *      and a row belongs to the organization that created it.
 *   2. Nothing leaves the public thread unsupervised except a `published` row —
 *      pre-moderation holds it, and removal hides it.
 *   3. A report is an accusation, never a verdict: it changes no status.
 *   4. Reading another business's reports requires `community.moderate`, which
 *      no business role holds.
 */
class OfferCommunityTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /** @return array{0: User, 1: Organization, 2: Offer} a live listing with its seller */
    protected function listing(): array
    {
        [$seller, $sellerOrg] = $this->accountWithOrganization('SellerAdmin', 'seller', 'شركة الجملة');
        $offer = $this->offer($sellerOrg);

        return [$seller, $sellerOrg, $offer];
    }

    /** @return array{0: User, 1: Organization} a buyer account */
    protected function buyer(string $name = 'محل التجزئة'): array
    {
        return $this->accountWithOrganization('BuyerAdmin', 'buyer', $name);
    }

    /** @return array{0: User, 1: Organization} platform staff */
    protected function platform(): array
    {
        return $this->accountWithOrganization('SuperAdmin', 'seller', 'منصة حصّال');
    }

    // ── Interest ────────────────────────────────────────────────────────────

    public function test_interest_is_one_row_per_actor_and_a_second_press_adds_nothing(): void
    {
        [, , $offer] = $this->listing();
        [$buyer, $buyerOrg] = $this->buyer();

        Sanctum::actingAs($buyer);
        $headers = $this->withTenant($buyerOrg);

        $this->withHeaders($headers)->postJson("/api/v1/marketplace/offers/{$offer->id}/like")
            ->assertCreated()
            ->assertJsonPath('data.liked', true)
            ->assertJsonPath('data.likes_count', 1)
            ->assertJsonPath('data.created', true);

        $this->withHeaders($headers)->postJson("/api/v1/marketplace/offers/{$offer->id}/like")
            ->assertCreated()
            ->assertJsonPath('data.likes_count', 1)
            ->assertJsonPath('data.created', false);

        $this->assertSame(1, OfferLike::query()->where('offer_id', $offer->id)->count());
    }

    public function test_the_same_person_acting_for_two_businesses_produces_two_signals(): void
    {
        [, , $offer] = $this->listing();
        [$buyer, $firstOrg] = $this->buyer('أولى');
        [, $secondOrg] = $this->buyer('ثانية');

        // One human, two companies: the second organization is added to the
        // same account rather than a second login being used, because the point
        // of the row is the company behind the click.
        $this->membership($secondOrg, $buyer, 'admin');

        Sanctum::actingAs($buyer);

        $this->withHeaders($this->withTenant($firstOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/like")->assertCreated();
        $this->withHeaders($this->withTenant($secondOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/like")
            ->assertCreated()
            ->assertJsonPath('data.likes_count', 2);

        // Interest is a claim by a company, so the row has to say which one.
        $this->assertSame(
            [$firstOrg->id, $secondOrg->id],
            OfferLike::query()->where('offer_id', $offer->id)->orderBy('id')->pluck('organization_id')->all()
        );
    }

    public function test_removing_interest_only_reaches_the_actors_own_row(): void
    {
        [, , $offer] = $this->listing();
        [$buyer, $buyerOrg] = $this->buyer();
        [$other, $otherOrg] = $this->buyer('منشأة أخرى');

        Sanctum::actingAs($buyer);
        $this->withHeaders($this->withTenant($buyerOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/like")->assertCreated();

        Sanctum::actingAs($other);
        $this->withHeaders($this->withTenant($otherOrg))
            ->deleteJson("/api/v1/marketplace/offers/{$offer->id}/like")
            ->assertOk()
            ->assertJsonPath('data.removed', false)
            ->assertJsonPath('data.likes_count', 1);

        $this->assertDatabaseHas('offer_likes', [
            'offer_id' => $offer->id,
            'user_id'  => $buyer->id,
        ]);

        Sanctum::actingAs($buyer);
        $this->withHeaders($this->withTenant($buyerOrg))
            ->deleteJson("/api/v1/marketplace/offers/{$offer->id}/like")
            ->assertOk()
            ->assertJsonPath('data.removed', true)
            ->assertJsonPath('data.likes_count', 0);
    }

    public function test_interest_is_never_recorded_for_a_company_the_caller_does_not_belong_to(): void
    {
        [, , $offer] = $this->listing();
        [$stranger, $strangerOrg] = $this->buyer('منشأة غريبة');
        [$member, $memberOrg] = $this->buyer('عضو');

        // A headerless request is not the tenant-less case: the context
        // middleware falls back to the caller's own first membership, which is
        // still an attributed row. The two things that really have no answer are
        // an account with no organization, and somebody else's organization.
        //
        // The account with no organization has to be one that still holds
        // `offers.like`, because a business capability no longer lives on an
        // account at all — an unaffiliated user is refused for lacking the
        // permission, which proves a different rule. A platform operator is the
        // account that can like things everywhere and belongs to no company.
        $nobody = $this->user('بلا منشأة');
        $nobody->assignRole('SuperAdmin');
        Sanctum::actingAs($nobody);

        $this->postJson("/api/v1/marketplace/offers/{$offer->id}/like")
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'TENANT_REQUIRED');

        $this->assertSame(0, OfferLike::query()->where('offer_id', $offer->id)->count());

        Sanctum::actingAs($stranger);
        $this->withHeaders($this->withTenant($memberOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/like")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ORGANIZATION_ACCESS_DENIED');

        $this->assertSame(0, OfferLike::query()->where('offer_id', $offer->id)->count());

        // The member, for their own company, is accepted.
        Sanctum::actingAs($member);
        $this->withHeaders($this->withTenant($memberOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/like")
            ->assertCreated();
        $this->assertSame($memberOrg->id, OfferLike::query()->where('offer_id', $offer->id)->value('organization_id'));
    }

    public function test_a_listing_nobody_can_buy_cannot_be_liked(): void
    {
        [$seller, $sellerOrg] = $this->accountWithOrganization('SellerAdmin', 'seller', 'شركة السحب');
        $offer = $this->offer($sellerOrg, ['status' => Offer::STATUS_DRAFT]);

        [$buyer, $buyerOrg] = $this->buyer();
        Sanctum::actingAs($buyer);

        $this->withHeaders($this->withTenant($buyerOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/like")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'OFFER_NOT_LIKEABLE');
    }

    public function test_the_liked_list_is_per_organization_not_per_person(): void
    {
        [, , $offer] = $this->listing();
        $second = $this->offer($this->organization('seller', 'ثانية'));
        [$buyer, $firstOrg] = $this->buyer('أولى');
        [, $secondOrg] = $this->buyer('ثانية');
        $this->membership($secondOrg, $buyer, 'admin');

        Sanctum::actingAs($buyer);
        $this->withHeaders($this->withTenant($firstOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/like")->assertCreated();
        $this->withHeaders($this->withTenant($secondOrg))
            ->postJson("/api/v1/marketplace/offers/{$second->id}/like")->assertCreated();

        $this->withHeaders($this->withTenant($firstOrg))
            ->getJson('/api/v1/marketplace/liked-offers')
            ->assertOk()
            ->assertJsonPath('data.offer_ids.0', $offer->id)
            ->assertJsonCount(1, 'data.offer_ids');

        $this->withHeaders($this->withTenant($secondOrg))
            ->getJson('/api/v1/marketplace/liked-offers')
            ->assertOk()
            ->assertJsonPath('data.offer_ids.0', $second->id);
    }

    // ── Questions ───────────────────────────────────────────────────────────

    public function test_a_question_and_the_sellers_answer_are_labelled_by_who_they_are(): void
    {
        [$seller, $sellerOrg] = $this->accountWithOrganization('SellerAdmin', 'seller', 'شركة الجملة');
        $offer = $this->offer($sellerOrg);
        [$buyer, $buyerOrg] = $this->buyer('بقالة النور');

        Sanctum::actingAs($buyer);
        $question = $this->withHeaders($this->withTenant($buyerOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/comments", ['body' => 'هل الكمية متوفرة للتسليم في عدن؟'])
            ->assertCreated()
            ->assertJsonPath('data.is_seller_reply', false)
            ->assertJsonPath('data.organization_name', $buyerOrg->name_ar)
            ->json('data');

        Sanctum::actingAs($seller);
        $answer = $this->withHeaders($this->withTenant($sellerOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/comments", [
                'body'      => 'نعم، المتوفر 100 كرتونة والجاهزية خلال يومين.',
                'parent_id' => $question['id'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_seller_reply', true)
            ->json('data');

        // The public thread is anonymous in the sense that it asks nothing: a
        // caller with no token still sees both rows.
        $this->getJson("/api/v1/marketplace/offers/{$offer->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.comments.0.replies.0.is_seller_reply', true)
            ->assertJsonPath('data.comments.0.replies.0.id', $answer['id']);
    }

    public function test_a_reply_cannot_be_attached_to_another_listings_thread(): void
    {
        [, , $offer] = $this->listing();
        [$otherSeller, $otherOrg] = $this->accountWithOrganization('SellerAdmin', 'seller', 'أخرى');
        $otherOffer = $this->offer($otherOrg);

        [$buyer, $buyerOrg] = $this->buyer();
        Sanctum::actingAs($buyer);

        $foreign = $this->withHeaders($this->withTenant($buyerOrg))
            ->postJson("/api/v1/marketplace/offers/{$otherOffer->id}/comments", ['body' => 'سؤال في عرض آخر'])
            ->assertCreated()
            ->json('data');

        $this->withHeaders($this->withTenant($buyerOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/comments", [
                'body'      => 'محاولة تعليق في غير مكانه',
                'parent_id' => $foreign['id'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'PARENT_COMMENT_MISMATCH');
    }

    public function test_pre_moderation_holds_a_comment_out_of_the_public_thread_until_a_reviewer_publishes_it(): void
    {
        $this->setSetting('community.moderate_before_publishing', true, 'boolean');

        [$seller, $sellerOrg, $offer] = $this->listing();
        [$buyer, $buyerOrg] = $this->buyer();
        [$platformUser, $platformOrg] = $this->platform();

        Sanctum::actingAs($buyer);
        $held = $this->withHeaders($this->withTenant($buyerOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/comments", ['body' => 'سؤال ينتظر المراجعة'])
            ->assertCreated()
            ->assertJsonPath('data.status', OfferComment::STATUS_PENDING)
            ->json('data');

        $this->getJson("/api/v1/marketplace/offers/{$offer->id}/comments")
            ->assertOk()
            ->assertJsonCount(0, 'data.comments');

        // Nobody who holds a business role can release it — not even the
        // account that receives the question.
        Sanctum::actingAs($seller);
        $this->withHeaders($this->withTenant($sellerOrg))
            ->putJson("/api/v1/community/comments/{$held['id']}/moderate", ['action' => 'publish'])
            ->assertForbidden();

        Sanctum::actingAs($platformUser);
        $this->withHeaders($this->withTenant($platformOrg))
            ->putJson("/api/v1/community/comments/{$held['id']}/moderate", ['action' => 'publish'])
            ->assertOk()
            ->assertJsonPath('data.status', OfferComment::STATUS_PUBLISHED);

        $this->getJson("/api/v1/marketplace/offers/{$offer->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'data.comments');
    }

    public function test_the_author_withdrawing_a_comment_hides_it_without_deleting_the_record(): void
    {
        [, , $offer] = $this->listing();
        [$buyer, $buyerOrg] = $this->buyer();

        Sanctum::actingAs($buyer);
        $comment = $this->withHeaders($this->withTenant($buyerOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/comments", ['body' => 'سأُسحب بعد قليل'])
            ->assertCreated()
            ->json('data');

        $this->withHeaders($this->withTenant($buyerOrg))
            ->deleteJson("/api/v1/marketplace/comments/{$comment['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', OfferComment::STATUS_REMOVED);

        $this->assertDatabaseHas('offer_comments', ['id' => $comment['id'], 'status' => OfferComment::STATUS_REMOVED]);
        $this->getJson("/api/v1/marketplace/offers/{$offer->id}/comments")
            ->assertOk()
            ->assertJsonCount(0, 'data.comments');
    }

    public function test_a_stranger_cannot_withdraw_another_business_comment(): void
    {
        [, , $offer] = $this->listing();
        [$victim, $victimOrg] = $this->buyer('ضحية');
        [$attacker, $attackerOrg] = $this->buyer('منافس');

        Sanctum::actingAs($victim);
        $comment = $this->withHeaders($this->withTenant($victimOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/comments", ['body' => 'تعليق لا يخص غيري'])
            ->assertCreated()
            ->json('data');

        Sanctum::actingAs($attacker);
        $this->withHeaders($this->withTenant($attackerOrg))
            ->deleteJson("/api/v1/marketplace/comments/{$comment['id']}")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'COMMENT_NOT_DELETABLE');

        $this->assertSame(OfferComment::STATUS_PUBLISHED, OfferComment::find($comment['id'])->status);
    }

    // ── Reports ─────────────────────────────────────────────────────────────

    public function test_a_report_asks_for_a_review_and_decides_nothing_itself(): void
    {
        [, , $offer] = $this->listing();
        [$buyer, $buyerOrg] = $this->buyer();
        [$reporter, $reporterOrg] = $this->buyer('ناقل البلاغ');

        Sanctum::actingAs($buyer);
        $comment = $this->withHeaders($this->withTenant($buyerOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/comments", ['body' => 'تعليق سيُبلَّغ عنه'])
            ->assertCreated()
            ->json('data');

        Sanctum::actingAs($reporter);
        $headers = $this->withTenant($reporterOrg);

        $this->withHeaders($headers)
            ->postJson("/api/v1/marketplace/comments/{$comment['id']}/report", ['reason' => 'spam'])
            ->assertCreated()
            ->assertJsonPath('data.report_count', 1)
            ->assertJsonPath('data.recorded', true);

        // The same actor reporting twice is one signal, not a louder one.
        $this->withHeaders($headers)
            ->postJson("/api/v1/marketplace/comments/{$comment['id']}/report", ['reason' => 'spam'])
            ->assertCreated()
            ->assertJsonPath('data.report_count', 1)
            ->assertJsonPath('data.recorded', false);

        // And the comment is still where it was, because an accusation from a
        // party in the disagreement is not a ruling.
        $this->assertSame(OfferComment::STATUS_PUBLISHED, OfferComment::find($comment['id'])->status);
        $this->assertDatabaseHas('offer_comments', ['id' => $comment['id'], 'report_count' => 1]);
    }

    public function test_the_moderation_queues_are_closed_to_business_roles_and_open_to_the_platform(): void
    {
        [, , $offer] = $this->listing();
        [$buyer, $buyerOrg] = $this->buyer();
        [$seller, $sellerOrg] = $this->accountWithOrganization('SellerAdmin', 'seller', 'شركة الجملة');
        [$platformUser, $platformOrg] = $this->platform();

        Sanctum::actingAs($buyer);
        $comment = $this->withHeaders($this->withTenant($buyerOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/comments", ['body' => 'يحتاج مراجعة'])
            ->assertCreated()
            ->json('data');
        $this->withHeaders($this->withTenant($buyerOrg))
            ->postJson("/api/v1/marketplace/comments/{$comment['id']}/report", ['reason' => 'abusive'])
            ->assertCreated();

        foreach ([[$seller, $sellerOrg], [$buyer, $buyerOrg]] as [$account, $org]) {
            Sanctum::actingAs($account);
            $this->withHeaders($this->withTenant($org))
                ->getJson('/api/v1/community/comments?status=reported')
                ->assertForbidden();
            $this->withHeaders($this->withTenant($org))
                ->getJson('/api/v1/community/reports')
                ->assertForbidden();
        }

        Sanctum::actingAs($platformUser);
        $this->withHeaders($this->withTenant($platformOrg))
            ->getJson('/api/v1/community/comments?status=reported')
            ->assertOk()
            ->assertJsonPath('data.0.id', $comment['id'])
            // The reviewer's desk shows whose listing it is about, which is the
            // enrichment this queue was asked for.
            ->assertJsonPath('data.0.offer.seller_org_id', $offer->seller_organization_id)
            ->assertJsonPath('data.0.reports.0.reason', 'abusive');

        $this->withHeaders($this->withTenant($platformOrg))
            ->getJson('/api/v1/community/summary')
            ->assertOk()
            ->assertJsonPath('data.open_reports', 1)
            ->assertJsonPath('data.reported_comments', 1);

        // Upholding with a removal is the only way a report changes a status.
        $this->withHeaders($this->withTenant($platformOrg))
            ->postJson("/api/v1/community/reports/" . OfferCommentReport::query()->latest('id')->value('id') . "/review", [
                'action'         => OfferCommentReport::STATUS_UPHELD,
                'remove_comment' => true,
                'reason'         => 'abusive',
            ])
            ->assertOk()
            ->assertJsonPath('data.comment_status', OfferComment::STATUS_REMOVED);

        $this->assertSame(1, OfferComment::find($comment['id'])->report_count);
    }

    public function test_the_listing_publishes_counts_it_can_prove_and_no_opinion_it_cannot(): void
    {
        [, , $offer] = $this->listing();
        [$buyer, $buyerOrg] = $this->buyer();

        Sanctum::actingAs($buyer);
        $this->withHeaders($this->withTenant($buyerOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/like")->assertCreated();
        $this->withHeaders($this->withTenant($buyerOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/comments", ['body' => 'سؤال يبقى معلناً'])
            ->assertCreated();

        $row = $this->getJson('/api/v1/marketplace/offers')->assertOk()->json('data.0');

        $this->assertSame(1, $row['likes_count']);
        $this->assertSame(1, $row['comments_count']);

        // No star, no score, no "popular": the platform has never computed a
        // judgement about a seller, so it must not publish one.
        foreach (['rating', 'rating_average', 'stars', 'score', 'popular'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row);
        }
    }
}
