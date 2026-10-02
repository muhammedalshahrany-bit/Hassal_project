<?php

namespace Tests\Feature\Community;

use App\Marketplace\Models\Offer;
use App\Community\Models\OfferComment;
use App\Community\Models\OfferCommentReport;
use App\Community\Models\OfferLike;
use App\Organizations\Models\Organization;
use App\Identity\Models\User;
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
 *   1. A count is derived from rows. Two presses by the same person are one
 *      row, attributed to the company in context when there is one and to the
 *      person alone when there is none — a consumer needs no organization.
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

    public function test_the_same_person_pressing_from_two_businesses_is_one_signal(): void
    {
        [, , $offer] = $this->listing();
        [$buyer, $firstOrg] = $this->buyer('أولى');
        [, $secondOrg] = $this->buyer('ثانية');

        // One human, two companies: the second organization is added to the
        // same account rather than a second login being used. Interest is a
        // person's claim, so the row they first made is the row that stands —
        // a second press from another office acknowledges, it does not add.
        $this->membership($secondOrg, $buyer, 'admin');

        Sanctum::actingAs($buyer);

        $this->withHeaders($this->withTenant($firstOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/like")->assertCreated();
        $this->withHeaders($this->withTenant($secondOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/like")
            ->assertCreated()
            ->assertJsonPath('data.likes_count', 1)
            ->assertJsonPath('data.created', false);

        // The row keeps the attribution it was born with: the company that was
        // in context when the interest was first recorded.
        $this->assertSame(
            [$firstOrg->id],
            OfferLike::query()->where('offer_id', $offer->id)->pluck('organization_id')->all()
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

        // A headerless request from an account with no memberships is the
        // tenant-less case, and it is now an answer rather than a refusal:
        // interest is a person capability, so an unaffiliated consumer likes
        // in their own name and the row carries a null organization.
        $nobody = $this->user('بلا منشأة');
        Sanctum::actingAs($nobody);

        $this->postJson("/api/v1/marketplace/offers/{$offer->id}/like")
            ->assertCreated()
            ->assertJsonPath('data.likes_count', 1);

        $this->assertNull(
            OfferLike::query()->where('offer_id', $offer->id)->where('user_id', $nobody->id)->value('organization_id')
        );

        // What is still refused is somebody else's organization: the context
        // middleware answers before the controller ever sees the request.
        Sanctum::actingAs($stranger);
        $this->withHeaders($this->withTenant($memberOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/like")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ORGANIZATION_ACCESS_DENIED');

        $this->assertSame(1, OfferLike::query()->where('offer_id', $offer->id)->count());

        // The member, for their own company, is accepted with attribution.
        Sanctum::actingAs($member);
        $this->withHeaders($this->withTenant($memberOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/like")
            ->assertCreated();
        $this->assertSame($memberOrg->id, OfferLike::query()->where('offer_id', $offer->id)->where('user_id', $member->id)->value('organization_id'));
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

    public function test_the_liked_list_is_per_person_across_every_company_they_work_for(): void
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

        // The fill state of a button belongs to the human pressing it, not to
        // the office they happened to be in: whichever company is in context,
        // both rows come back, or the client would light a second press that
        // records nothing.
        $this->withHeaders($this->withTenant($firstOrg))
            ->getJson('/api/v1/marketplace/liked-offers')
            ->assertOk()
            ->assertJsonCount(2, 'data.offer_ids');

        $this->withHeaders($this->withTenant($secondOrg))
            ->getJson('/api/v1/marketplace/liked-offers')
            ->assertOk()
            ->assertJsonCount(2, 'data.offer_ids');

        // And a consumer with no company at all reads the same list from their
        // own name. `flushHeaders` because `withHeaders` above set defaults
        // that persist on the test client: without the flush this request
        // would arrive carrying the previous actor's organization.
        $this->flushHeaders();
        $nobody = $this->user('مستهلك');
        Sanctum::actingAs($nobody);
        $this->getJson('/api/v1/marketplace/liked-offers')
            ->assertOk()
            ->assertJsonCount(0, 'data.offer_ids');
    }

    // ── The consumer's standing ─────────────────────────────────────────────

    public function test_a_consumer_with_no_organization_likes_and_is_named_by_person_only(): void
    {
        [, , $offer] = $this->listing();
        $consumer = $this->user('مستهلك');

        Sanctum::actingAs($consumer);

        // No tenant header anywhere in this test: the whole point is that the
        // consumer's standing is the account itself.
        $this->postJson("/api/v1/marketplace/offers/{$offer->id}/like")->assertCreated();

        $comment = $this->postJson("/api/v1/marketplace/offers/{$offer->id}/comments",
            ['body' => 'هل التوصيل متاح إلى حولي؟'])
            ->assertCreated()
            ->assertJsonPath('data.is_seller_reply', false)
            ->assertJsonPath('data.identity_hidden', false)
            ->json('data');

        // The author is present because attribution is the rule; the
        // organization is absent because there is none to name — and `shape`
        // drops nulls, so absent means the key is not there at all.
        $this->assertArrayHasKey('author_name', $comment);
        $this->assertArrayNotHasKey('organization_name', $comment);
        $this->assertArrayNotHasKey('organization_id', $comment);

        $this->getJson("/api/v1/marketplace/offers/{$offer->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'data.comments');

        // §14 names notifications as part of the consumer experience, and the
        // read is strictly per-user in `NotificationController`, so the standing
        // list carries it: an unaffiliated account gets its own inbox, 200 and
        // an empty one — not a 403 that says it does not exist yet.
        $this->getJson('/api/v1/notifications')->assertOk();

        // And trading — the act that does need a counterparty to trust — is
        // still refused for the same account: `requests.create` is a business
        // capability, no person holds it, and the permission door answers
        // before any controller can.
        $this->postJson('/api/v1/purchase-requests', ['offer_id' => $offer->id, 'quantity' => 5])
            ->assertForbidden();
    }

    public function test_a_consumer_report_is_recorded_and_names_no_company(): void
    {
        [, , $offer] = $this->listing();
        [$buyer, $buyerOrg] = $this->buyer();
        $consumer = $this->user('مبلغ');

        Sanctum::actingAs($buyer);
        $comment = $this->withHeaders($this->withTenant($buyerOrg))
            ->postJson("/api/v1/marketplace/offers/{$offer->id}/comments", ['body' => 'تعليق يراه الجميع'])
            ->assertCreated()
            ->json('data');

        Sanctum::actingAs($consumer);
        // The buyer's organization header from the request above is a client
        // default, not a per-request one: it has to be flushed or this report
        // would file itself under a company the reporter does not belong to.
        $this->flushHeaders();
        $this->postJson("/api/v1/marketplace/comments/{$comment['id']}/report", ['reason' => 'spam'])
            ->assertCreated()
            ->assertJsonPath('data.recorded', true);

        $this->assertNull(
            OfferCommentReport::query()->where('offer_comment_id', $comment['id'])->value('organization_id')
        );

        // The platform reviewer still reads the report; the null organization
        // changes the side's label, not the queue's existence.
        [$platformUser, $platformOrg] = $this->platform();
        Sanctum::actingAs($platformUser);
        $this->withHeaders($this->withTenant($platformOrg))
            ->getJson('/api/v1/community/comments?status=reported')
            ->assertOk()
            ->assertJsonPath('data.0.id', $comment['id']);
    }

    public function test_a_suspended_account_holds_no_person_capability(): void
    {
        [, , $offer] = $this->listing();
        $consumer = $this->user('موقوف');
        $consumer->forceFill(['status' => 'suspended'])->save();

        Sanctum::actingAs($consumer);

        $this->postJson("/api/v1/marketplace/offers/{$offer->id}/like")
            ->assertForbidden();

        $this->assertSame(0, OfferLike::query()->where('offer_id', $offer->id)->count());
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
