<?php

namespace App\Community\Services;

use App\Core\Exceptions\ApiRefused;
use App\Community\Models\OfferComment;
use App\Community\Models\OfferCommentReport;
use App\Identity\Models\User;
use App\Marketplace\Models\Offer;
use App\Platform\Services\SystemSettingsService;
use App\Core\Tenant\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may say what on a listing, and what happens when they take it back.
 *
 * Three properties the whole feature rests on:
 *
 *   1. Every comment names its author, and names their organization when the
 *      author was acting as one. A consumer asking a question in their own
 *      name is an attributed buyer, not graffiti — what the thread forbids is
 *      an anonymous remark, not an unaffiliated one.
 *   2. A comment written by the selling business is labelled as such
 *      (`is_seller_reply`), because a buyer must be able to tell an answer from
 *      a rumour. The badge answers "did the company behind this listing write
 *      this?", so it holds for a seller's own opening note as much as for their
 *      answer underneath someone else's question — and a consumer's null
 *      organization never matches it.
 *   3. Pre-moderation is an operator policy (`community.moderate_before_
 *      publishing`), not a judgement this code makes about anybody.
 *
 * Depth one, in both directions: replying to a reply flattens onto the question
 * it answers, because a marketplace thread is not a conversation tree. And a
 * withdrawn comment takes its replies with it — they would otherwise sit in the
 * thread answering nothing.
 */
class OfferCommentService
{
    public function __construct(
        private readonly SystemSettingsService $settings,
    ) {
    }

    /**
     * Ask a question, or answer one.
     *
     * @param  array{body: string, parent_id?: int|null}  $validated
     */
    public function ask(Offer $offer, User $author, array $validated): OfferComment
    {
        // Null is a consumer asking in their own name, not a failure: the
        // author row is always filled, the organization only when one is in
        // context. A question needs no counterparty trust because it promises
        // no trade — a purchase request does, and that door stays company-only.
        $organizationId = TenantContext::id();

        $parent = null;

        if (!empty($validated['parent_id'])) {
            $parent = OfferComment::find($validated['parent_id']);

            // A reply belongs to one thread; nesting under another offer's
            // comment would put words where they were never asked.
            if (!$parent || $parent->offer_id !== $offer->id) {
                throw new ApiRefused(
                    'التعليق الأصلي لا يخص هذا العرض',
                    'PARENT_COMMENT_MISMATCH',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $parent = $parent->parent_id ? OfferComment::find($parent->parent_id) : $parent;
        }

        return OfferComment::create([
            'offer_id'        => $offer->id,
            'organization_id' => $organizationId,
            'user_id'         => $author->id,
            'parent_id'       => $parent?->id,
            'is_seller_reply' => $organizationId !== null
                && $organizationId === $offer->seller_organization_id,
            'body'            => trim($validated['body']),
            'status'          => $this->holdsBeforePublishing()
                ? OfferComment::STATUS_PENDING
                : OfferComment::STATUS_PUBLISHED,
        ]);
    }

    /**
     * Withdraw a comment.
     *
     * The author's own account, an owner or admin of the organization that wrote
     * it, and platform staff may remove it; a colleague in a different business
     * may not. The row stays with status `removed` rather than disappearing,
     * because a thread that silently rewrites itself is the thing a dispute
     * later turns on.
     */
    public function withdraw(OfferComment $comment, User $actor): void
    {
        $actingOrgId = TenantContext::id();

        // A person withdraws their own words whoever they were acting as when
        // they wrote them — a consumer's question is theirs to take back.
        $isAuthor = $comment->user_id === $actor->id;

        // The officer path needs a real company in context: null on both sides
        // is a consumer and a consumer's row, not a shared office.
        $isOrgOfficer = $actingOrgId !== null
            && $actingOrgId === $comment->organization_id
            && in_array(TenantContext::role(), ['owner', 'admin'], true);

        if (!$isAuthor && !$isOrgOfficer && !$actor->isPlatformOperator()) {
            throw new ApiRefused(
                'لا يمكنك سحب هذا التعليق',
                'COMMENT_NOT_DELETABLE',
                Response::HTTP_FORBIDDEN
            );
        }

        $comment->recordDecision(
            OfferComment::STATUS_REMOVED,
            $actor->id,
            ($isAuthor || $isOrgOfficer)
                ? OfferComment::REASON_OTHER . ':withdrawn_by_author'
                : OfferComment::REASON_OTHER . ':withdrawn_by_platform'
        );

        $comment->replies()->update(['status' => OfferComment::STATUS_REMOVED]);
    }

    /**
     * Ask a human to look at a comment.
     *
     * Reporting never hides anything by itself — the reporter is one of the
     * parties in the disagreement, and letting an accusation act as a verdict
     * turns the report queue into a takedown service. What it does is put the
     * comment in front of `community.moderate` with the reason attached.
     *
     * Keyed on the person again: one accusation per reporter per comment,
     * whether they filed it from a company office or as a consumer. The company
     * in context is recorded when there is one, because a reviewer weighing a
     * business-versus-business dispute wants to see the sides. A repeated report
     * from the same actor is acknowledged, not counted.
     *
     * @param  array{reason: string, note?: string|null}  $validated
     * @return array{report: OfferCommentReport, comment: OfferComment}
     */
    public function report(OfferComment $comment, User $reporter, array $validated): array
    {
        $report = OfferCommentReport::firstOrCreate(
            [
                'offer_comment_id' => $comment->id,
                'user_id'          => $reporter->id,
            ],
            [
                'organization_id' => TenantContext::id(),
                'reason'          => $validated['reason'],
                'note'            => $validated['note'] ?? null,
            ]
        );

        if (!$report->wasRecentlyCreated && ($validated['note'] ?? null) !== null) {
            $report->update(['reason' => $validated['reason'], 'note' => $validated['note']]);
        }

        if ($report->wasRecentlyCreated) {
            $comment->increment('report_count');
        }

        return ['report' => $report, 'comment' => $comment->fresh()];
    }

    public function holdsBeforePublishing(): bool
    {
        return filter_var(
            $this->settings->get('community.moderate_before_publishing', '0'),
            FILTER_VALIDATE_BOOLEAN
        );
    }
}
