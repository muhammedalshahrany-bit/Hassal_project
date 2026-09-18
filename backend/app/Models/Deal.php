<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deal extends Model
{
    use HasFactory, SoftDeletes;

    // Deal Lifecycle State Machine constants
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PENDING_SELLER_APPROVAL = 'PENDING_SELLER_APPROVAL';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_PENDING_PAYMENT = 'PENDING_PAYMENT';
    public const STATUS_ESCROW_FUNDED = 'ESCROW_FUNDED';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_READY_FOR_PICKUP = 'READY_FOR_PICKUP';
    public const STATUS_DISPATCHED = 'DISPATCHED';
    public const STATUS_DELIVERED = 'DELIVERED';
    public const STATUS_INSPECTION = 'INSPECTION';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_SETTLED = 'SETTLED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_DISPUTED = 'DISPUTED';
    public const STATUS_RESOLVED = 'RESOLVED';
    public const STATUS_REFUNDED = 'REFUNDED';

    protected $fillable = [
        'deal_number',
        'offer_id',
        'reservation_id',
        'buyer_organization_id',
        'seller_organization_id',
        'buyer_user_id',
        'seller_user_id',
        'warehouse_id',
        'quantity',
        'unit_price',
        'currency_code',
        'transaction_currency',
        'base_currency',
        'exchange_rate',
        'exchange_rate_snapshot',
        'rate_source',
        'rate_timestamp',
        'base_amount',
        'discount_amount',
        'tax_rate_snapshot',
        'tax_amount',
        'platform_fee_rate_snapshot',
        'platform_fee_amount',
        'delivery_fee_amount',
        'buyer_total_amount',
        'seller_net_amount',
        'status',
        'delivery_method',
        'delivery_address',
        'inspection_window_hours',
        'inspection_deadline_at',
        'accepted_at',
        'paid_at',
        'dispatched_at',
        'delivered_at',
        'completed_at',
        'settled_at',
        'cancelled_at',
        'cancellation_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'exchange_rate_snapshot' => 'decimal:6',
            'rate_timestamp' => 'datetime',
            'base_amount' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_rate_snapshot' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'platform_fee_rate_snapshot' => 'decimal:4',
            'platform_fee_amount' => 'decimal:4',
            'delivery_fee_amount' => 'decimal:4',
            'buyer_total_amount' => 'decimal:4',
            'seller_net_amount' => 'decimal:4',
            'inspection_window_hours' => 'integer',
            'inspection_deadline_at' => 'datetime',
            'accepted_at' => 'datetime',
            'paid_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
            'settled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function buyerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'buyer_organization_id');
    }

    public function sellerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'seller_organization_id');
    }

    public function buyerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function sellerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DealItem::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(DealStatusHistory::class)->orderBy('created_at', 'asc');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function escrow(): HasOne
    {
        return $this->hasOne(Escrow::class);
    }

    public function dispute(): HasOne
    {
        return $this->hasOne(Dispute::class);
    }
}
