<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One report about the platform itself, and the platform's answer to it.
 *
 * Not a comment report (`offer_comment_reports`), and not a dispute
 * (`disputes`): those two are one business objecting to another business. This
 * is a person — buyer, seller, staff, anyone with a session — objecting to
 * HASSAL. That distinction is why it has its own table rather than a `type`
 * column on one of them: the audiences, the reviewers and the consequences of
 * ignoring each are different, and a queue that mixes "this comment is abusive"
 * with "the app loses my price" gets triaged by whichever one a reviewer happens
 * to read first.
 *
 * What a ticket does *not* carry: a severity. A platform that has never measured
 * how long its own reports take cannot calibrate a severity scale, and the field
 * would be a guess rendered as a number. `status` and the timestamps are the
 * facts; how urgent something is stays in `message`, in the reporter's words.
 */
class SupportTicket extends Model
{
    use HasFactory;

    // What kind of thing went wrong, in the platform's own vocabulary rather
    // than the reporter's. The six exist because each one goes to a different
    // place: a bug in code, data that is wrong in the database, an account that
    // cannot be opened, a listing or trade that behaved badly, a request for
    // something that does not exist yet, and the remainder that a human has to
    // read. A free-text "type" answers nothing.
    public const CATEGORY_FUNCTIONAL_BUG = 'FUNCTIONAL_BUG';
    public const CATEGORY_WRONG_DATA     = 'WRONG_DATA';
    public const CATEGORY_ACCOUNT_ACCESS = 'ACCOUNT_ACCESS';
    public const CATEGORY_LISTING_TRADE  = 'LISTING_TRADE';
    public const CATEGORY_SUGGESTION     = 'SUGGESTION';
    public const CATEGORY_OTHER          = 'OTHER';

    public const CATEGORIES = [
        self::CATEGORY_FUNCTIONAL_BUG,
        self::CATEGORY_WRONG_DATA,
        self::CATEGORY_ACCOUNT_ACCESS,
        self::CATEGORY_LISTING_TRADE,
        self::CATEGORY_SUGGESTION,
        self::CATEGORY_OTHER,
    ];

    // open      — filed, nobody has touched it
    // in_review — somebody is, which is the answer to "is anyone reading these"
    // resolved  — the reported thing was fixed or explained, and `reply` says so
    // dismissed — looked at and deliberately not changed; also answered in `reply`
    //
    // There is no `closed` beside `resolved` and `dismissed`: a third end state
    // for the same fact ("we are done with it") would leave the reporter's
    // question — was it fixed, or refused? — depending on which word an agent
    // preferred.
    public const STATUS_OPEN      = 'open';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_RESOLVED  = 'resolved';
    public const STATUS_DISMISS   = 'dismissed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_REVIEW,
        self::STATUS_RESOLVED,
        self::STATUS_DISMISS,
    ];

    /** Statuses a ticket may not move backwards out of without an answer. */
    public const ANSWERED_STATUSES = [self::STATUS_RESOLVED, self::STATUS_DISMISS];

    /**
     * Nothing on this row is mass-assignable.
     *
     * The distinction `category`, `message` and `screen` would have been
     * fillable for is one this table cannot afford: a request that can set
     * `status` can close its own report, and one that can set `user_id` can file
     * a complaint against a business it does not belong to. So every write names
     * itself as a server-side decision with `forceFill()`, which is also how the
     * four guarded columns on `Offer` are handled.
     */
    protected $guarded = ['*'];

    /**
     * The keys a client may describe itself with.
     *
     * A whitelist rather than "store whatever JSON arrives", because `context` is
     * read by a support agent as fact: a client that could put a token, an email
     * or a made-up build number in there would have the agent acting on it. The
     * five below are labels about the software, and the controller keeps only
     * these — anything else in the payload is dropped before it reaches the row.
     */
    public const CONTEXT_KEYS = ['platform', 'app_version', 'locale', 'screen_label', 'device_name'];

    protected function casts(): array
    {
        return [
            'context'     => 'array',
            'answered_at' => 'datetime',
        ];
    }

    /**
     * The reference a person is given and quotes back.
     *
     * Derived from the id, so it cannot collide and does not need a sequence of
     * its own; zero-padded so it sorts the way it reads.
     */
    public static function referenceFor(int $id): string
    {
        return 'SUP-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    public function filer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    /**
     * Whether a human still owes this report an answer.
     *
     * Used by the queue's summary and nothing else — the four statuses above are
     * what is stored, and this is a reading of them, so it cannot drift into
     * being a fifth state somebody writes to the database.
     */
    public function needsAttention(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_IN_REVIEW], true);
    }
}
