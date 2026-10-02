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

    // ─── Deal Lifecycle State Machine ────────────────────────────────────────
    //
    // The chain is the commercial one this platform actually serves: a listing
    // becomes a request, the seller's acceptance turns it into a held quantity
    // and then into this order, the goods move, the handover is attested by
    // scanning the deal's own code, and the buyer's receipt confirmation closes
    // it. There is no state that says HASSAL is holding anybody's money,
    // because it is not: settlement happens between the two businesses, outside
    // the platform, and the amounts on this row are the record of what was
    // agreed — not a balance the platform can pay out.
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PENDING_SELLER_APPROVAL = 'PENDING_SELLER_APPROVAL';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_READY_FOR_PICKUP = 'READY_FOR_PICKUP';
    public const STATUS_DISPATCHED = 'DISPATCHED';
    public const STATUS_DELIVERED = 'DELIVERED';
    public const STATUS_INSPECTION = 'INSPECTION';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_DISPUTED = 'DISPUTED';
    public const STATUS_RESOLVED = 'RESOLVED';

    /**
     * Every state this machine can hold, in the order the chain walks through it.
     *
     * `RbacMatrixTest` and the contract's §4.7 both read this list, and the two
     * clients render their labels from the server's answer — so the place a new
     * state has to be added is here, once, not four times.
     */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_SELLER_APPROVAL,
        self::STATUS_ACCEPTED,
        self::STATUS_PROCESSING,
        self::STATUS_READY_FOR_PICKUP,
        self::STATUS_DISPATCHED,
        self::STATUS_DELIVERED,
        self::STATUS_INSPECTION,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_DISPUTED,
        self::STATUS_RESOLVED,
    ];

    /** States no further transition can leave. */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /**
     * States an order sits in once the seller has accepted it, and therefore the
     * states in which it has a handover code.
     *
     * Acceptance is what issues the code, so everywhere that asks "should this
     * order have a code to show?" reads this list rather than restating a run of
     * state names it could get wrong. `DISPUTED` and `RESOLVED` belong here
     * because an order can fall into either from any point of the chain, and
     * whatever it was carrying it still carries.
     */
    public const FULFILLMENT_STATUSES = [
        self::STATUS_ACCEPTED,
        self::STATUS_PROCESSING,
        self::STATUS_READY_FOR_PICKUP,
        self::STATUS_DISPATCHED,
        self::STATUS_DELIVERED,
        self::STATUS_INSPECTION,
        self::STATUS_DISPUTED,
        self::STATUS_RESOLVED,
    ];

    protected $fillable = [
        'deal_number',
        'offer_id',
        'reservation_id',
        'purchase_request_id',
        'buyer_organization_id',
        'seller_organization_id',
        'buyer_user_id',
        'seller_user_id',
        'warehouse_id',
        'quantity',
        'unit_price',
        'currency_code',
        'base_currency',
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
        'dispatched_at',
        'delivered_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:4',
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
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
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

    /**
     * The buyer's ask that led here, when the deal came through a purchase
     * request rather than a direct reservation.
     */
    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
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

    /**
     * The code that attests the physical handover of this order's goods.
     *
     * One per deal, issued when the seller accepts the order, spent when it is
     * scanned at delivery — see `App\Models\DealHandover` and §4.7.
     */
    public function handover(): HasOne
    {
        return $this->hasOne(DealHandover::class);
    }

    public function dispute(): HasOne
    {
        return $this->hasOne(Dispute::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}
