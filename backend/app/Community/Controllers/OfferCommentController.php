<?php

namespace App\Community\Controllers;

use App\Core\Exceptions\ApiRefused;
use App\Community\Models\OfferComment;
use App\Community\Models\OfferCommentReport;
use App\Community\Services\OfferCommentService;
use App\Core\Http\ApiResponse;
use App\Core\Http\Controllers\Controller;
use App\Marketplace\Models\Offer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public question-and-answer thread on a listing.
 *
 * Only `published` rows leave this controller unsupervised. A reviewer's queue
 * is a separate, permission-gated endpoint, so a held or removed comment cannot
 * reach the public thread by being fetched through the wrong door.
 *
 * Whether a remark may be filed, what it is nested under, whether it is held
 * for review, who may take it back and what a report does to a comment is
 * thread law and lives in App\Community\Services\OfferCommentService. Reading
 * the thread and shaping what the public is shown is here.
 */
class OfferCommentController extends Controller
{
    public function __construct(protected OfferCommentService $thread) {}

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

    /**
     * Ask a question, or answer one.
     */
    public function store(Request $request, int $id): JsonResponse
    {
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

        try {
            $comment = $this->thread->ask($offer, $request->user(), $validated);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

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
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $comment = OfferComment::with('offer')->find($id);

        if (!$comment) {
            return ApiResponse::error('التعليق غير موجود', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        try {
            $this->thread->withdraw($comment, $request->user());
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(['id' => $comment->id, 'status' => $comment->status], 'تم سحب التعليق');
    }

    /**
     * Ask a human to look at a comment.
     *
     * Reporting never hides anything by itself; it puts the comment in front of
     * `community.moderate` with the reason attached.
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

        $filed = $this->thread->report($comment, $request->user(), $validated);

        return ApiResponse::success([
            'comment_id'   => $comment->id,
            'report_count' => $filed['comment']->report_count,
            'recorded'     => $filed['report']->wasRecentlyCreated,
        ], 'تم تسجيل البلاغ لمراجعة فريق المنصة', Response::HTTP_CREATED);
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
