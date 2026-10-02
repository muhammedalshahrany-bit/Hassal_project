<?php

namespace App\Trading\Models;

use App\Identity\Models\User;
use App\Organizations\Models\Organization;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispute extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'OPEN';
    public const STATUS_UNDER_REVIEW = 'UNDER_REVIEW';
    public const STATUS_EVIDENCE_REQUIRED = 'EVIDENCE_REQUIRED';

    // The two things a ruling can do here. The platform holds no money, so it
    // has no vocabulary of refunds and partial refunds: it either lets the order
    // stand (`RESOLVED_RELEASE` — the goods were as agreed, the chain carries on
    // to the buyer's receipt) or it calls the order off (`RESOLVED_CANCELLATION`
    // — the quantity goes back to the shelf and the handover code is retired).
    // Why the platform ruled that way is `resolution_notes`, which is prose and
    // not a fifth state.
    public const STATUS_RESOLVED_RELEASE = 'RESOLVED_RELEASE';
    public const STATUS_RESOLVED_CANCELLATION = 'RESOLVED_CANCELLATION';

    /** The whole vocabulary one column holds. */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_EVIDENCE_REQUIRED,
        self::STATUS_RESOLVED_RELEASE,
        self::STATUS_RESOLVED_CANCELLATION,
    ];

    /** Still awaiting the platform's decision. */
    public const OPEN_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_EVIDENCE_REQUIRED,
    ];

    /** Decided, and no longer open to a second ruling. */
    public const RESOLVED_STATUSES = [
        self::STATUS_RESOLVED_RELEASE,
        self::STATUS_RESOLVED_CANCELLATION,
    ];

    /**
     * What the complaint is about.
     *
     * Declared here because three clients label these strings and the platform
     * triages by them: a name that exists only in a migration comment is a name
     * each client is free to spell differently.
     */
    public const REASONS = [
        'QUALITY_DEFECT',
        'QUANTITY_MISMATCH',
        'WRONG_ITEM',
        'DELIVERY_DELAY',
        'TEMPERATURE_ABUSE',
        'OTHER',
    ];

    protected $fillable = [
        'dispute_number',
        'deal_id',
        'initiator_organization_id',
        'initiator_user_id',
        'reason',
        'description',
        'claimed_amount',
        'evidence_urls',
        'status',
        'resolution_notes',
        'resolved_by_user_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'claimed_amount' => 'decimal:4',
            'evidence_urls' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function initiatorOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'initiator_organization_id');
    }

    public function initiatorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_user_id');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
