<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Offer;
use App\Models\OfferComment;
use App\Models\OfferCommentReport;
use App\Models\Organization;
use App\Models\OrganizationDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The reviewer's desk for public conversation on listings.
 *
 * Every route here carries `community.moderate`, which no business role holds:
 * deciding whether another company's employees may speak in public is the same
 * kind of act as approving another company's listing, and both belong to the
 * platform.
 *
 * Decisions are recorded on the row — who, when, and the reason from a fixed
 * vocabulary — so an appeal has something to point at, and so "we removed it
 * because the account that asked loudest" cannot become the operating rule.
 */
class CommunityModerationController extends Controller
{
    /**
     * The comment queue.
     *
     * `pending` is the working queue when pre-moderation is on. `reported` is
     * not a status — it is a filter over published comments that somebody
     * flagged, and it is the queue that matters when pre-moderation is off.
     */
    public function comments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status'  => ['nullable', Rule::in([OfferComment::STATUS_PENDING, OfferComment::STATUS_PUBLISHED, OfferComment::STATUS_REMOVED, 'reported'])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $validated['status'] ?? OfferComment::STATUS_PENDING;

        $query = OfferComment::query()
            ->with([
                'offer:id,title_ar,status,seller_organization_id',
                'offer.seller:id,name_ar,commercial_registration_number,vat_number,status',
                'organization:id,name_ar,name_en,status',
                'author:id,name,email',
                'reports.reporter:id,name',
                'moderator:id,name',
            ]);

        if ($status === 'reported') {
            $query->where('status', OfferComment::STATUS_PUBLISHED)
                  ->where('report_count', '>', 0)
                  ->orderByDesc('report_count');
        } else {
            $query->where('status', $status)->orderBy('id');
        }

        $page = $query->paginate($validated['perPage'] ?? 25)
            ->through(fn (OfferComment $c) => $this->shape($c));

        return ApiResponse::paginated($page, 'قائمة مراجعة التعليقات');
    }

    /**
     * Publish, hide, or hold a comment.
     */
    public function moderate(Request $request, int $id): JsonResponse
    {
        $comment = OfferComment::find($id);

        if (!$comment) {
            return ApiResponse::error('التعليق غير موجود', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'action' => ['required', Rule::in(['publish', 'remove', 'pending'])],
            'reason' => ['required_if:action,remove', 'nullable', Rule::in(OfferComment::REASONS)],
        ]);

        $status = match ($validated['action']) {
            'publish' => OfferComment::STATUS_PUBLISHED,
            'remove'  => OfferComment::STATUS_REMOVED,
            default   => OfferComment::STATUS_PENDING,
        };

        $comment->update([
            'status'            => $status,
            'moderated_by'      => $request->user()->id,
            'moderated_at'      => now(),
            'moderation_reason' => $validated['reason'] ?? null,
        ]);

        // Publishing a thread's root does not silently publish the held replies
        // under it; each row was held for its own reason and gets its own
        // decision.
        return ApiResponse::success($this->shape($comment->fresh()), 'تم تسجيل قرار المراجعة');
    }

    /**
     * The report queue: accusations, with the comment they are about.
     */
    public function reports(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in([OfferCommentReport::STATUS_OPEN, OfferCommentReport::STATUS_UPHELD, OfferCommentReport::STATUS_DISMISSED])],
        ]);

        $page = OfferCommentReport::query()
            ->with(['comment:id,offer_id,body,status,report_count', 'comment.offer:id,title_ar,seller_organization_id', 'reporter:id,name', 'organization:id,name_ar', 'reviewer:id,name'])
            ->where('status', $validated['status'] ?? OfferCommentReport::STATUS_OPEN)
            ->orderBy('id')
            ->paginate(min((int) ($request->input('perPage') ?? 25), 100));

        return ApiResponse::paginated($page, 'قائمة البلاغات');
    }

    /**
     * Uphold or dismiss a report — optionally taking the comment down in the
     * same decision, which is the only way an upheld report does anything.
     */
    public function reviewReport(Request $request, int $id): JsonResponse
    {
        $report = OfferCommentReport::with('comment')->find($id);

        if (!$report) {
            return ApiResponse::error('البلاغ غير موجود', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'action'         => ['required', Rule::in([OfferCommentReport::STATUS_UPHELD, OfferCommentReport::STATUS_DISMISSED])],
            'remove_comment' => ['nullable', 'boolean'],
            'reason'         => ['required_if:action,' . OfferCommentReport::STATUS_UPHELD, 'nullable', Rule::in(OfferComment::REASONS)],
        ]);

        $action = $validated['action'];

        $report->update([
            'status'      => $action,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $comment = $report->comment;

        if ($comment && $action === OfferCommentReport::STATUS_UPHELD && filter_var($validated['remove_comment'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $comment->update([
                'status'            => OfferComment::STATUS_REMOVED,
                'moderated_by'      => $request->user()->id,
                'moderated_at'      => now(),
                'moderation_reason' => $validated['reason'],
            ]);
        }

        return ApiResponse::success([
            'report_id'   => $report->id,
            'status'      => $report->status,
            'comment_status' => $comment?->fresh()?->status,
        ], 'تم تسجيل قرار البلاغ');
    }

    /**
     * What is waiting on a reviewer, in one call.
     *
     * The admin console shows these as badges; the numbers are counted from the
     * same tables the queues read, so a badge can never claim work that the
     * queue it opens does not contain.
     */
    public function summary(): JsonResponse
    {
        return ApiResponse::success([
            'pending_offers'     => Offer::query()->where('status', Offer::STATUS_PENDING_REVIEW)->count(),
            'pending_comments'   => OfferComment::query()->where('status', OfferComment::STATUS_PENDING)->count(),
            'reported_comments'  => OfferComment::query()->where('status', OfferComment::STATUS_PUBLISHED)->where('report_count', '>', 0)->count(),
            'open_reports'       => OfferCommentReport::query()->where('status', OfferCommentReport::STATUS_OPEN)->count(),
            'pending_documents'  => OrganizationDocument::query()->where('status', OrganizationDocument::STATUS_PENDING)->count(),
            'pending_organizations' => Organization::query()->where('status', Organization::STATUS_PENDING)->count(),
        ], 'ملخص ما ينتظر المراجعة');
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(OfferComment $comment): array
    {
        return [
            'id'            => $comment->id,
            'body'          => $comment->body,
            'status'        => $comment->status,
            'report_count'  => $comment->report_count,
            'is_seller_reply' => $comment->is_seller_reply,
            'created_at'    => $comment->created_at,
            'moderated_at'  => $comment->moderated_at,
            'moderation_reason' => $comment->moderation_reason,
            'moderator_name' => $comment->moderator?->name,
            'author_name'   => $comment->author?->name,
            'organization'  => $comment->organization ? [
                'id'     => $comment->organization->id,
                'name_ar' => $comment->organization->name_ar,
                'status' => $comment->organization->status,
            ] : null,
            'offer'         => $comment->offer ? [
                'id'          => $comment->offer->id,
                'title_ar'    => $comment->offer->title_ar,
                'status'      => $comment->offer->status,
                'seller_org_id' => $comment->offer->seller_organization_id,
                'seller_name_ar' => $comment->offer->seller?->name_ar,
                'seller_cr'   => $comment->offer->seller?->commercial_registration_number,
                'seller_vat'  => $comment->offer->seller?->vat_number,
                'seller_status' => $comment->offer->seller?->status,
            ] : null,
            'reports'       => $comment->reports->map(fn (OfferCommentReport $r) => [
                'id'     => $r->id,
                'reason' => $r->reason,
                'note'   => $r->note,
                'status' => $r->status,
                'by_organization' => $r->organization?->name_ar,
                'reported_by' => $r->reporter?->name,
            ])->all(),
        ];
    }
}
