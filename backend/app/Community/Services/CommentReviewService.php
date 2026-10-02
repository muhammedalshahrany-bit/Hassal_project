<?php

namespace App\Community\Services;

use App\Community\Models\OfferComment;
use App\Community\Models\OfferCommentReport;
use App\Identity\Models\User;

/**
 * The reviewer's decisions, and what each one is allowed to do to a row.
 *
 * Decisions are recorded on the comment — who, when, and the reason from a
 * fixed vocabulary — so an appeal has something to point at, and so "we removed
 * it because the account that asked loudest" cannot become the operating rule.
 *
 * Publishing a thread's root does not silently publish the held replies under
 * it: each row was held for its own reason and gets its own decision.
 *
 * Upholding a report does nothing on its own unless the reviewer also takes the
 * comment down, which is why the two writes are one call and one decision — a
 * report marked upheld over a comment still in public view is a record of a
 * verdict nobody carried out.
 */
class CommentReviewService
{
    /**
     * Publish, hide, or hold one comment.
     *
     * @param  string  $action  one of `publish`, `remove`, `pending`
     */
    public function decide(OfferComment $comment, string $action, User $reviewer, ?string $reason): OfferComment
    {
        $status = match ($action) {
            'publish' => OfferComment::STATUS_PUBLISHED,
            'remove'  => OfferComment::STATUS_REMOVED,
            default   => OfferComment::STATUS_PENDING,
        };

        $comment->recordDecision($status, $reviewer->id, $reason);

        return $comment;
    }

    /**
     * Uphold or dismiss a report, optionally taking the comment down with it.
     *
     * @return OfferComment|null  the comment the decision was about
     */
    public function ruleReport(OfferCommentReport $report, string $action, User $reviewer, bool $removeComment, ?string $reason): ?OfferComment
    {
        $report->update([
            'status'      => $action,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $comment = $report->comment;

        if ($comment && $action === OfferCommentReport::STATUS_UPHELD && $removeComment) {
            $comment->recordDecision(OfferComment::STATUS_REMOVED, $reviewer->id, $reason);
        }

        return $comment;
    }
}
