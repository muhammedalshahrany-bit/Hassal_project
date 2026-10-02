<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A public question or remark on a listing, written by a named business.
 *
 * `STATUS_PENDING` is only reached through a rule, never through a guess about
 * who is behaving well: a comment is written published when the platform has
 * pre-moderation off, and written pending when
 * `community.moderate_before_publishing` is on (an operator choice in
 * `SettingCatalog`, not a constant here). Being reported does not hide a
 * comment; it queues one for a human.
 */
class OfferComment extends Model
{
    use HasFactory;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REMOVED   = 'removed';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_PUBLISHED, self::STATUS_REMOVED];

    /** Why a comment came down, offered to the reviewer as a vocabulary. */
    public const REASON_SPAM        = 'spam';
    public const REASON_OFF_TOPIC   = 'off_topic';
    public const REASON_ABUSIVE     = 'abusive';
    public const REASON_CONTACT_INFO = 'contact_info';
    public const REASON_MISLEADING  = 'misleading';
    public const REASON_OTHER       = 'other';

    public const REASONS = [
        self::REASON_SPAM,
        self::REASON_OFF_TOPIC,
        self::REASON_ABUSIVE,
        self::REASON_CONTACT_INFO,
        self::REASON_MISLEADING,
        self::REASON_OTHER,
    ];

    protected $fillable = [
        'offer_id',
        'organization_id',
        'user_id',
        'parent_id',
        'is_seller_reply',
        'body',
        'status',
        'report_count',
        'moderated_by',
        'moderated_at',
        'moderation_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_seller_reply' => 'boolean',
            'report_count' => 'integer',
            'moderated_at' => 'datetime',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(OfferCommentReport::class, 'offer_comment_id');
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
