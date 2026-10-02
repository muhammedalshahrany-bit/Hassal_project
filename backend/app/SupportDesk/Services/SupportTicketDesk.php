<?php

namespace App\SupportDesk\Services;

use App\Core\Tenant\TenantContext;
use App\Notifications\Services\NotificationManager;
use App\Identity\Models\User;
use App\Core\Exceptions\ApiRefused;
use App\SupportDesk\Models\SupportTicket;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The desk's own rules: what may be filed twice, what a report is numbered
 * from, and what has to be said before a report can be closed.
 *
 *   * The same words from the same person minutes apart are one report pressed
 *     twice — a double-tap, a retry after a spinner, or a bot working a form.
 *     Counting them as two would make the queue's numbers mean nothing, so the
 *     second one is refused with the first one's reference rather than silently
 *     dropped: "already filed" is only an answer if the person is handed the
 *     number to check.
 *   * `resolved` and `dismissed` require a reply. A ticket marked done with
 *     nothing said to the person who filed it is a report the platform buried,
 *     and `message` is the only record of what was decided.
 *   * `in_review` is a claim, not an answer: it tells the next agent somebody is
 *     on it. It is what makes "is anyone reading these" an answerable question
 *     when the honest alternative is two people working the same report.
 *
 * A reply here is not an edit of the reporter's words, and the reporter's
 * original message is never rewritten by an answer.
 */
class SupportTicketDesk
{
    public function __construct(
        private readonly NotificationManager $notificationManager,
    ) {
    }

    /**
     * Open one report.
     *
     * @param  array{category: string, message: string, screen?: string|null}  $validated
     * @param  array<string, mixed>  $incomingContext  exactly what the client sent under `context`
     */
    public function file(User $reporter, array $validated, array $incomingContext): SupportTicket
    {
        // Duplicate window: ten minutes of the same category and the same
        // sentence from the same account is one complaint.
        $clash = SupportTicket::query()
            ->where('user_id', $reporter->id)
            ->where('category', $validated['category'])
            ->where('message', trim($validated['message']))
            ->where('created_at', '>=', now()->subMinutes(10))
            ->latest('id')
            ->first();

        if ($clash) {
            throw new ApiRefused(
                'سبقك هذا البلاغ قبل دقائق؛ هو مسجَّل برقمه ولا حاجة لتكراره.',
                'DUPLICATE_REPORT',
                Response::HTTP_CONFLICT,
                ['reference' => $clash->reference]
            );
        }

        return DB::transaction(function () use ($validated, $reporter, $incomingContext): SupportTicket {
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
                'user_id'         => $reporter->id,
                'organization_id' => TenantContext::id(),
                'category'        => $validated['category'],
                'message'         => trim($validated['message']),
                'screen'          => $validated['screen'] ?? null,
                'context'         => $this->clientContext($incomingContext),
                'status'          => SupportTicket::STATUS_OPEN,
            ])->save();

            // The second write is the reference, and it is second because the
            // number is derived from the row's id — which makes it unique
            // without a second sequence to keep in step, and unguessable by a
            // client. A ticket nobody gave a number to never reaches a reader
            // because both writes share this transaction.
            $ticket->forceFill([
                'reference' => SupportTicket::referenceFor($ticket->id),
            ])->save();

            return $ticket;
        });
    }

    /**
     * Answer, or claim, one report.
     *
     * Moving back out of an answered state needs no new permission, but it
     * re-stamps `answered_at`, so "we answered" always means "this is the latest
     * answer" rather than a date from a decision that was reversed.
     *
     * @param  array{status: string, reply?: string|null}  $validated
     */
    public function answer(SupportTicket $ticket, array $validated, User $answerer): SupportTicket
    {
        $answering = in_array($validated['status'], SupportTicket::ANSWERED_STATUSES, true);

        if ($answering && blank($validated['reply'] ?? null)) {
            throw new ApiRefused(
                'الإغلاق بلا ردٍّ على صاحب البلاغ ليس معالجةً، بل إخفاءً.',
                'REPLY_REQUIRED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $ticket->forceFill([
            'status'      => $validated['status'],
            'reply'       => $validated['reply'] ?? $ticket->reply,
            'answered_by' => $answering ? $answerer->id : $ticket->answered_by,
            'answered_at' => $answering ? now() : $ticket->answered_at,
        ])->save();

        // The reporter is told once, and only when an answer exists to be read:
        // pinging them for a status change nobody has explained would train them
        // to ignore the notice that carries the reply.
        if ($answering && $ticket->reply && $user = $ticket->filer) {
            $this->notificationManager->notify(
                $user,
                $validated['status'] === SupportTicket::STATUS_RESOLVED
                    ? 'تمت معالجة بلاغك ' . $ticket->reference
                    : 'ردٌّ على بلاغك ' . $ticket->reference,
                str($ticket->reply)->limit(140)->toString(),
                ['type' => 'SUPPORT', 'support_ticket_id' => $ticket->id, 'reference' => $ticket->reference]
            );
        }

        return $ticket;
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
     * @param  array<string, mixed>  $incoming
     * @return array<string, string>|null
     */
    private function clientContext(array $incoming): ?array
    {
        $kept = [];

        foreach (SupportTicket::CONTEXT_KEYS as $key) {
            $value = $incoming[$key] ?? null;
            if (is_string($value) || is_int($value)) {
                $kept[$key] = str((string) $value)->limit(60)->toString();
            }
        }

        return $kept === [] ? null : $kept;
    }
}
