<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Offer;
use App\Models\OfferComment;
use App\Models\OfferCommentReport;
use App\Services\SystemSettingsService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public question-and-answer thread on a listing.
 *
 * Three properties the whole feature rests on:
 *
 *   1. Every comment names its author, and names their organization when the
 *      author was acting as one. A consumer asking a question in their own
 *      name is an attributed buyer, not graffiti — what the thread forbids is
 *      an anonymous remark, not an unaffiliated one.
 *   2. A comment written by the selling business is labelled as such
 *      (`is_seller_reply`). Alibaba, IndiaMART and OpenSooq all do this: a
 *      buyer must be able to tell an answer from a rumour.
 *   3. Only `published` rows leave this controller unsupervised. A reviewer's
 *      queue is a separate, permission-gated endpoint, so a held or removed
 *      comment cannot reach the public thread by being fetched through the
 *      wrong door.
 */
class OfferCommentController extends Controller
{
    public function __construct(protected SystemSettingsService $settings) {}

    /**
     * The thread as the public sees it: published roots, each with its
     * published replies underneath.
     */
    public function index(Request $request, int $id): JsonResponse
    {
        $offer = Offer::find($id);

        if (!$offer) {
            return ApiResponse::error('العرض غير موجود', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        // `offer` is loaded because every row is shaped against the listing's
        // seller-identity switch; without it a thread of thirty questions would
        // read the same offer thirty times.
        $comments = OfferComment::query()
            ->with(['offer:id,show_seller_identity', 'organization:id,name_ar,name_en', 'author:id,name', 'replies.offer:id,show_seller_identity', 'replies.organization:id,name_ar,name_en', 'replies.author:id,name'])
            ->where('offer_id', $offer->id)
            ->where('status', OfferComment::STATUS_PUBLISHED)
            ->whereNull('parent_id')
            ->orderBy('id')
            ->get();

        return ApiResponse::success([
            'offer_id' => $offer->id,
            'comments' => $comments->map(fn (OfferComment $c) => $this->shape($c, withReplies: true))->all(),
        ], 'تم استرجاع أسئلة العروض');
    }

    public function store(Request $request, int $id): JsonResponse
    {
        // Null is a consumer asking in their own name, not a failure: the
        // author row is always filled, the organization only when one is in
        // context. A question needs no counterparty trust because it promises
        // no trade — a purchase request does, and that door stays company-only.
        $organizationId = TenantContext::id();

        $offer = Offer::find($id);

        if (!$offer || !in_array($offer->status, [Offer::STATUS_ACTIVE, Offer::STATUS_PAUSED, Offer::STATUS_SOLD_OUT], true)) {
            return ApiResponse::error(
                'لا يمكن التعليق على هذا العرض',
                'OFFER_NOT_COMMENTABLE',
                Response::HTTP_NOT_FOUND
            );
        }

        $validated = $request->validate([
            'body'      => ['required', 'string', 'min:2', 'max:2000'],
            'parent_id' => ['nullable', 'integer', Rule::exists('offer_comments', 'id')],
        ]);

        $parent = null;

        if (!empty($validated['parent_id'])) {
            $parent = OfferComment::find($validated['parent_id']);

            // A reply belongs to one thread; nesting under another offer's
            // comment would put words where they were never asked.
            if (!$parent || $parent->offer_id !== $offer->id) {
                return ApiResponse::error(
                    'التعليق الأصلي لا يخص هذا العرض',
                    'PARENT_COMMENT_MISMATCH',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            // Depth one, in both directions: replying to a reply flattens onto
            // the question it answers, because a marketplace thread is not a
            // conversation tree.
            $parent = $parent->parent_id ? OfferComment::find($parent->parent_id) : $parent;
        }

        $comment = OfferComment::create([
            'offer_id'        => $offer->id,
            'organization_id' => $organizationId,
            'user_id'         => $request->user()->id,
            'parent_id'       => $parent?->id,
            // The badge answers "did the company behind this listing write
            // this?", so it holds for a seller's own opening note as much as for
            // their answer underneath someone else's question — and a consumer's
            // null organization never matches it.
            'is_seller_reply' => $organizationId !== null
                && $organizationId === $offer->seller_organization_id,
            'body'            => trim($validated['body']),
            // Pre-moderation is an operator policy (`community.moderate_before_
            // publishing`), not a judgement this controller makes about anybody.
            'status'          => $this->holdsBeforePublishing()
                ? OfferComment::STATUS_PENDING
                : OfferComment::STATUS_PUBLISHED,
        ]);

        return ApiResponse::success(
            $this->shape($comment),
            $comment->status === OfferComment::STATUS_PENDING
                ? 'تم إرسال سؤالك، سيظهر للعامة بعد مراجعة فريق المنصة'
                : 'تم نشر تعليقك',
            Response::HTTP_CREATED
        );
    }

    /**
     * Withdraw a comment.
     *
     * The author's own account, an owner or admin of the organization that
     * wrote it, and platform staff may remove it; a colleague in a different
     * business may not. The row stays with status `removed` rather than
     * disappearing, because a thread that silently rewrites itself is the thing
     * a dispute later turns on.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $comment = OfferComment::with('offer')->find($id);

        if (!$comment) {
            return ApiResponse::error('التعليق غير موجود', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        $user = $request->user();
        $actingOrgId = TenantContext::id();
        // A person withdraws their own words whoever they were acting as when
        // they wrote them — a consumer's question is theirs to take back.
        $isAuthor = $comment->user_id === $user->id;
        // The officer path needs a real company in context: null on both sides
        // is a consumer and a consumer's row, not a shared office.
        $isOrgOfficer = $actingOrgId !== null
            && $actingOrgId === $comment->organization_id
            && in_array(TenantContext::role(), ['owner', 'admin'], true);

        if (!$isAuthor && !$isOrgOfficer && !$user->hasRole('SuperAdmin')) {
            return ApiResponse::error(
                'لا يمكنك سحب هذا التعليق',
                'COMMENT_NOT_DELETABLE',
                Response::HTTP_FORBIDDEN
            );
        }

        $comment->update([
            'status'          => OfferComment::STATUS_REMOVED,
            'moderated_by'    => $user->id,
            'moderated_at'    => now(),
            'moderation_reason' => $isAuthor || $isOrgOfficer
                ? OfferComment::REASON_OTHER . ':withdrawn_by_author'
                : OfferComment::REASON_OTHER . ':withdrawn_by_platform',
        ]);

        // A withdrawn comment still answers to the questions hanging under it,
        // so its replies are hidden with it rather than left in the thread
        // replying to nothing.
        $comment->replies()->update(['status' => OfferComment::STATUS_REMOVED]);

        return ApiResponse::success(['id' => $comment->id, 'status' => $comment->status], 'تم سحب التعليق');
    }

    /**
     * Ask a human to look at a comment.
     *
     * Reporting never hides anything by itself — the reporter is one of the
     * parties in the disagreement, and letting an accusation act as a verdict
     * turns the report queue into a takedown service. What it does is put the
     * comment in front of `community.moderate` with the reason attached.
     */
    public function report(Request $request, int $id): JsonResponse
    {
        $comment = OfferComment::find($id);

        if (!$comment || !$comment->isPublished()) {
            return ApiResponse::error('التعليق غير متاح للبلاغ', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'reason' => ['required', Rule::in(OfferCommentReport::REASONS)],
            'note'   => ['nullable', 'string', 'max:500'],
        ]);

        // Keyed on the person again: one accusation per reporter per comment,
        // whether they filed it from a company office or as a consumer. The
        // company in context is recorded when there is one, because a reviewer
        // weighing a business-versus-business dispute wants to see the sides.
        $report = OfferCommentReport::firstOrCreate(
            [
                'offer_comment_id' => $comment->id,
                'user_id'          => $request->user()->id,
            ],
            [
                'organization_id' => TenantContext::id(),
                'reason' => $validated['reason'],
                'note'   => $validated['note'] ?? null,
            ]
        );

        // A repeated report from the same actor is acknowledged, not counted.
        if (!$report->wasRecentlyCreated && ($validated['note'] ?? null) !== null) {
            $report->update(['reason' => $validated['reason'], 'note' => $validated['note']]);
        }

        if ($report->wasRecentlyCreated) {
            $comment->increment('report_count');
        }

        return ApiResponse::success([
            'comment_id'   => $comment->id,
            'report_count' => $comment->fresh()->report_count,
            'recorded'     => $report->wasRecentlyCreated,
        ], 'تم تسجيل البلاغ لمراجعة فريق المنصة', Response::HTTP_CREATED);
    }

    private function holdsBeforePublishing(): bool
    {
        return filter_var(
            $this->settings->get('community.moderate_before_publishing', '0'),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(OfferComment $comment, bool $withReplies = false): array
    {
        // A seller who hid their name on the listing must not have it printed one
        // line below in their own answer to a question. The switch covers the
        // thread as well as the card, and it is applied to the seller's posts
        // only — a buyer asking publicly chose to be named when they asked.
        //
        // The flag is sent rather than a sentence: the server does not own the
        // wording of a field the client can display in either language.
        $hidden = $comment->is_seller_reply
            && !$comment->offer?->getAttribute('show_seller_identity');

        return array_filter([
            'id'               => $comment->id,
            'offer_id'         => $comment->offer_id,
            'body'             => $comment->body,
            'author_name'      => $hidden ? null : $comment->author?->name,
            'organization_id'  => $hidden ? null : $comment->organization_id,
            'organization_name' => $hidden ? null : $comment->organization?->name_ar,
            'identity_hidden'  => $hidden,
            'is_seller_reply'  => $comment->is_seller_reply,
            'status'           => $comment->status,
            'report_count'     => $comment->report_count,
            'created_at'       => $comment->created_at,
            'replies'          => $withReplies
                ? $comment->replies->where('status', OfferComment::STATUS_PUBLISHED)
                    ->map(fn (OfferComment $reply) => $this->shape($reply))->all()
                : null,
        ], fn ($value) => $value !== null);
    }
}
