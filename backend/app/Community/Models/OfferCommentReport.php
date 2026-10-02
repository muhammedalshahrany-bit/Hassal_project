<?php

namespace App\Community\Models;

use App\Identity\Models\User;
use App\Organizations\Models\Organization;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One business telling the platform that a comment needs a human look.
 *
 * The reporter chooses a reason from a fixed vocabulary and may add a note. A
 * reviewer upholds or dismisses it, and that decision is stored — so "the
 * platform ignored it" and "the platform looked and disagreed" are two
 * different, provable things.
 */
class OfferCommentReport extends Model
{
    use HasFactory;

    public const STATUS_OPEN      = 'open';
    public const STATUS_UPHELD    = 'upheld';
    public const STATUS_DISMISSED = 'dismissed';

    public const REASONS = [
        OfferComment::REASON_SPAM,
        OfferComment::REASON_OFF_TOPIC,
        OfferComment::REASON_ABUSIVE,
        OfferComment::REASON_CONTACT_INFO,
        OfferComment::REASON_MISLEADING,
        OfferComment::REASON_OTHER,
    ];

    protected $fillable = [
        'offer_comment_id',
        'organization_id',
        'user_id',
        'reason',
        'note',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(OfferComment::class, 'offer_comment_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
