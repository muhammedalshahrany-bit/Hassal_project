<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispute extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'OPEN';
    public const STATUS_UNDER_REVIEW = 'UNDER_REVIEW';
    public const STATUS_EVIDENCE_REQUIRED = 'EVIDENCE_REQUIRED';
    public const STATUS_RESOLVED_REFUND = 'RESOLVED_REFUND';
    public const STATUS_RESOLVED_RELEASE = 'RESOLVED_RELEASE';
    public const STATUS_RESOLVED_PARTIAL = 'RESOLVED_PARTIAL';
    public const STATUS_REJECTED = 'REJECTED';

    /** The whole vocabulary one column holds. */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_EVIDENCE_REQUIRED,
        self::STATUS_RESOLVED_REFUND,
        self::STATUS_RESOLVED_RELEASE,
        self::STATUS_RESOLVED_PARTIAL,
        self::STATUS_REJECTED,
    ];

    /** Still awaiting the platform's decision. */
    public const OPEN_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_EVIDENCE_REQUIRED,
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
        'resolution_decision',
        'refunded_amount',
        'resolution_notes',
        'resolved_by_user_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'claimed_amount' => 'decimal:4',
            'refunded_amount' => 'decimal:4',
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
