<?php

namespace App\SupportDesk\Controllers;

use App\Core\Http\ApiResponse;
use App\Core\Http\Controllers\Controller;
use App\Core\Exceptions\ApiRefused;
use App\SupportDesk\Models\SupportTicket;
use App\SupportDesk\Services\SupportTicketDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform's own complaint desk: what is broken, who said so, and what was
 * answered.
 *
 * Two doors, and the difference between them is the whole design:
 *
 *   * Anyone with a session may **file** and may read **their own** reports.
 *     There is no permission on either. Reporting that the app lost your price
 *     is not a platform capability somebody can be granted or denied — and a
 *     person who cannot reach the desk because their membership lapsed is a
 *     person who will tell everyone the platform is unreachable.
 *   * Only `support.manage` may read the **queue**, and only `support.manage`
 *     may answer. That capability belongs to no business standing, for the same
 *     reason `offers.moderate` does not: deciding that a competitor's report was
 *     dismissed is judging someone else's case.
 *
 * What this is not: it is not a help centre, and it is not a chat. One report,
 * one thread, one decision — the shape that leaves a provable record.
 *
 * Deciding whether a report is a duplicate, what it is numbered from, and
 * whether an answer may close it is desk law and lives in
 * App\SupportDesk\Services\SupportTicketDesk. Listing and reading rows is here.
 */
class SupportController extends Controller
{
    public function __construct(protected SupportTicketDesk $desk) {}

    /**
     * File a report.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'message'  => ['required', 'string', 'min:15', 'max:4000'],
            // A route name, not prose: the queue sorts by it, and a free-text
            // "where" field becomes five spellings of the same screen.
            'screen'   => ['nullable', 'string', 'max:80', 'regex:/^[\w\-\/\.]+$/'],
            'context'  => ['nullable', 'array'],
        ]);

        try {
            $ticket = $this->desk->file($request->user(), $validated, (array) $request->input('context', []));
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $this->shape($ticket, staff: false),
            'تم استلام بلاغك',
            Response::HTTP_CREATED
        );
    }

    /**
     * The reporter's own reports, newest first.
     *
     * Filtered by `user_id` and nothing else — not by the acting organization —
     * because a person's reports follow them between businesses, and a report
     * filed before joining a company is not that company's to read.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SupportTicket::with('answerer:id,name')
            ->where('user_id', $request->user()->id);

        if ($request->filled('status')) {
            $validated = $request->validate(['status' => ['required', Rule::in(SupportTicket::STATUSES)]]);
            $query->where('status', $validated['status']);
        }

        $tickets = $query->latest('id')->paginate(min(50, max(1, (int) $request->input('per_page', 20))));

        return ApiResponse::paginated(
            $tickets->through(fn (SupportTicket $ticket) => $this->shape($ticket, staff: false)),
            'تم استرجاع بلاغاتك'
        );
    }

    /**
     * The platform's queue, across every reporter.
     */
    public function queue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status'   => ['nullable', Rule::in(SupportTicket::STATUSES)],
            'category' => ['nullable', Rule::in(SupportTicket::CATEGORIES)],
        ]);

        $query = SupportTicket::with(['filer:id,name,email', 'answerer:id,name', 'organization:id,name_ar,name_en'])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['category'] ?? null, fn ($q, $category) => $q->where('category', $category));

        $tickets = $query->oldest('id')->paginate(min(100, max(1, (int) $request->input('per_page', 25))));

        return ApiResponse::paginated(
            $tickets->through(fn (SupportTicket $ticket) => $this->shape($ticket, staff: true)),
            'تم استرجاع بلاغات الدعم'
        );
    }

    /**
     * Counts a console can render without paging through the queue.
     *
     * `oldest_open_minutes` is measured, not asserted: "the desk is backed up"
     * has to be a number somebody can disagree with.
     */
    public function summary(): JsonResponse
    {
        $counts = SupportTicket::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $oldestOpen = SupportTicket::query()
            ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_IN_REVIEW])
            ->oldest('created_at')
            ->value('created_at');

        return ApiResponse::success([
            'by_status' => [
                'open'      => (int) ($counts[SupportTicket::STATUS_OPEN] ?? 0),
                'in_review' => (int) ($counts[SupportTicket::STATUS_IN_REVIEW] ?? 0),
                'resolved'  => (int) ($counts[SupportTicket::STATUS_RESOLVED] ?? 0),
                'dismissed' => (int) ($counts[SupportTicket::STATUS_DISMISS] ?? 0),
            ],
            'awaiting_attention' => (int) ($counts[SupportTicket::STATUS_OPEN] ?? 0)
                + (int) ($counts[SupportTicket::STATUS_IN_REVIEW] ?? 0),
            'oldest_open_at'      => $oldestOpen,
            'oldest_open_minutes' => $oldestOpen ? (int) $oldestOpen->diffInMinutes(now()) : null,
        ], 'ملخص بلاغات الدعم');
    }

    /**
     * Answer, or claim, one report.
     */
    public function answer(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(SupportTicket::STATUSES)],
            'reply'  => ['nullable', 'string', 'min:10', 'max:4000'],
        ]);

        $ticket = SupportTicket::query()->find($id);

        if (!$ticket) {
            return ApiResponse::error('البلاغ غير موجود', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        try {
            $this->desk->answer($ticket, $validated, $request->user());
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $this->shape(
                $ticket->fresh(['answerer:id,name', 'filer:id,name', 'organization:id,name_ar,name_en']),
                staff: true
            ),
            'تم تحديث البلاغ'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(SupportTicket $ticket, bool $staff): array
    {
        return [
            'id'            => $ticket->id,
            'reference'     => $ticket->reference,
            'category'      => $ticket->category,
            'message'       => $ticket->message,
            'screen'        => $ticket->screen,
            'context'       => $ticket->context,
            'status'        => $ticket->status,
            'reply'         => $ticket->reply,
            'answered_at'   => $ticket->answered_at,
            'answered_by'   => $staff ? ($ticket->answerer?->name) : null,
            // Who filed it, and against which business — the queue's whole value
            // to an agent. A report whose author the desk cannot name is a note
            // in a drawer: the answer has to reach somebody, and a duplicate or
            // an escalating account is only visible once rows can be traced back
            // to one person. The reporter is not told this column exists; they
            // already know who they are, and the queue is the only reader.
            'filed_by'      => $staff ? ($ticket->filer?->name) : null,
            'organization_name' => $staff
                ? ($ticket->organization?->name_ar ?: $ticket->organization?->name_en)
                : null,
            'organization_id' => $ticket->organization_id,
            'created_at'    => $ticket->created_at,
            'updated_at'    => $ticket->updated_at,
        ];
    }

}
