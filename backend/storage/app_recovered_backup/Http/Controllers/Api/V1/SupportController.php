<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\SupportTicket;
use App\Services\Notifications\NotificationManager;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
 * one thread, one decision — the shape that leaves a provable record. A reply
 * here is not an edit of the reporter's words, and the reporter's original
 * message is never rewritten by an answer.
 */
class SupportController extends Controller
{
    public function __construct(protected NotificationManager $notificationManager) {}

    /**
     * File a report.
     *
     * `reference` is minted from the row's own id inside the transaction, so it
     * is unique without a second sequence to keep in step and cannot be chosen by
     * a client — a report number somebody else can guess is not an identifier,
     * it is an invitation.
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

        $user = $request->user();

        // The same words from the same person minutes apart are one report
        // pressed twice — a double-tap, a retry after a spinner, or a bot
        // working a form. Counting them as two would make the queue's numbers
        // mean nothing, so the second one is refused with the first one's
        // reference rather than silently dropped: "already filed" is only an
        // answer if the person is handed the number to check.
        $clash = SupportTicket::query()
            ->where('user_id', $user->id)
            ->where('category', $validated['category'])
            ->where('message', trim($validated['message']))
            ->where('created_at', '>=', now()->subMinutes(10))
            ->latest('id')
            ->first();

        if ($clash) {
            return ApiResponse::error(
                'سبقك هذا البلاغ قبل دقائق؛ هو مسجَّل برقمه ولا حاجة لتكراره.',
                'DUPLICATE_REPORT',
                Response::HTTP_CONFLICT,
                ['reference' => $clash->reference]
            );
        }

        $ticket = DB::transaction(function () use ($validated, $request, $user): SupportTicket {
            $ticket = new SupportTicket();

            // `forceFill` for every one of these columns, for the reason that
            // applies to all of them: a report's author, the business it was
            // filed against, and its state are the platform's facts about the
            // row, and a column a request may set is a column a request will
            // set. So `user_id` comes from the resolved session,
            // `organization_id` from `TenantContext` — which is null for a
            // signed-in person with no acting business, a real and allowed
            // reporter — and the status arrives at its only opening value.
            $ticket->forceFill([
                'user_id'         => $user->id,
                'organization_id' => TenantContext::id(),
                'category'        => $validated['category'],
                'message'         => trim($validated['message']),
                'screen'          => $validated['screen'] ?? null,
                'context'         => $this->clientContext($request),
                'status'          => SupportTicket::STATUS_OPEN,
            ])->save();

            // The second write is the reference, and it is second because the
            // number is derived from the row's id. Inside the same transaction,
            // so a ticket nobody gave a number to never reaches a reader.
            $ticket->forceFill([
                'reference' => SupportTicket::referenceFor($ticket->id),
            ])->save();

            return $ticket;
        });

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
     *
     * The status vocabulary is the one the row is stored in, and this is the only
     * route that writes it. Two rules hold it honest:
     *
     *   * `resolved` and `dismissed` require a `reply`. A ticket marked done with
     *     nothing said to the person who filed it is a report the platform
     *     buried, and `message` is the only record of what was decided.
     *   * Moving back out of an answered state needs no new permission, but it
     *     re-stamps `answered_at`, so "we answered" always means "this is the
     *     latest answer" rather than a date from a decision that was reversed.
     *
     * `in_review` is a claim, not an answer: it tells the next agent somebody is
     * on it. It is what makes "is anyone reading these" an answerable question
     * when the honest alternative is two people working the same report.
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

        if (in_array($validated['status'], SupportTicket::ANSWERED_STATUSES, true) && blank($validated['reply'] ?? null)) {
            return ApiResponse::error(
                'الإغلاق بلا ردٍّ على صاحب البلاغ ليس معالجةً، بل إخفاءً.',
                'REPLY_REQUIRED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $answering = in_array($validated['status'], SupportTicket::ANSWERED_STATUSES, true);

        $ticket->forceFill([
            'status'      => $validated['status'],
            'reply'       => $validated['reply'] ?? $ticket->reply,
            'answered_by' => $answering ? $request->user()->id : $ticket->answered_by,
            'answered_at' => $answering ? now() : $ticket->answered_at,
        ])->save();

        // The reporter is told once, and only when an answer exists to be read:
        // pinging them for a status change nobody has explained would train them
        // to ignore the notice that carries the reply.
        if ($answering && $ticket->reply) {
            if ($user = $ticket->filer) {
                $this->notificationManager->notify(
                    $user,
                    $validated['status'] === SupportTicket::STATUS_RESOLVED
                        ? 'تمت معالجة بلاغك ' . $ticket->reference
                        : 'ردٌّ على بلاغك ' . $ticket->reference,
                    str($ticket->reply)->limit(140)->toString(),
                    ['type' => 'SUPPORT', 'support_ticket_id' => $ticket->id, 'reference' => $ticket->reference]
                );
            }
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
     * The client's own description of itself, kept to the five keys the model
     * names.
     *
     * Everything else in the payload is dropped rather than stored: `context` is
     * read by an agent as a fact about the software, so a field a client can
     * invent — a token in a `build` key, an email in `platform` — is not
     * environment data, it is unverified text wearing a label.
     *
     * @return array<string, string>|null
     */
    private function clientContext(Request $request): ?array
    {
        $incoming = is_array($request->input('context')) ? $request->input('context') : [];

        $kept = [];
        foreach (SupportTicket::CONTEXT_KEYS as $key) {
            $value = $incoming[$key] ?? null;
            if (is_string($value) || is_int($value)) {
                $kept[$key] = str((string) $value)->limit(60)->toString();
            }
        }

        return $kept === [] ? null : $kept;
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
