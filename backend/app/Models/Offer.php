<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Offer extends Model
{
    use HasFactory, SoftDeletes;

    // Constants for opportunity reasons
    public const REASON_SURPLUS_STOCK = 'SURPLUS_STOCK';
    public const REASON_SLOW_MOVING = 'SLOW_MOVING';
    public const REASON_SEASONAL_STOCK = 'SEASONAL_STOCK';
    public const REASON_EXCESS_INVENTORY = 'EXCESS_INVENTORY';
    public const REASON_LIQUIDATION = 'LIQUIDATION';
    public const REASON_BRANCH_CLOSURE = 'BRANCH_CLOSURE';
    public const REASON_ASSORTMENT_CHANGE = 'ASSORTMENT_CHANGE';
    public const REASON_LOW_LOCAL_DEMAND = 'LOW_LOCAL_DEMAND';

    /**
     * Offer statuses — the whole vocabulary.
     *
     * DRAFT           created, not submitted
     * PENDING_REVIEW  submitted, waiting on the platform (`offer.requires_review`)
     * ACTIVE          published and buyable
     * PAUSED          the seller hid it temporarily; content and stock are intact
     * SOLD_OUT        nothing left available
     * EXPIRED         `expires_at` passed
     * CANCELLED       terminal; the seller withdrew it
     *
     * Two states were removed rather than kept: FULLY_RESERVED and ARCHIVED.
     * A reservation is a quantity counter, not a commercial state — encoding it
     * as a status gave the same fact two homes that could drift apart, and a
     * seller could "archive" a listing the buyer still needed to see was gone.
     * Anything not in this list must not appear in the `offers.status` column.
     */
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PENDING_REVIEW = 'PENDING_REVIEW';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_PAUSED = 'PAUSED';
    public const STATUS_SOLD_OUT = 'SOLD_OUT';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_CANCELLED = 'CANCELLED';

    /**
     * The full status vocabulary, in listing order.
     *
     * Anything the API accepts as a `status` filter or transition target is
     * checked against this array, so a new state cannot be accepted by a
     * validator before it is named here.
     */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_REVIEW,
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_SOLD_OUT,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Reconcile the status with the available quantity after stock moves.
     *
     * Only two inventory facts are strong enough to change a status: nothing
     * left (SOLD_OUT) and stock returning to a listing that said it was empty
     * (back to ACTIVE). A paused offer stays paused when stock comes back —
     * the seller hid it on purpose, and the reservation system is not
     * permission to republish it.
     */
    public static function statusAfterQuantityChange(string $currentStatus, float $availableQuantity): string
    {
        $isPublished = in_array($currentStatus, [self::STATUS_ACTIVE, self::STATUS_SOLD_OUT, self::STATUS_PAUSED], true);

        if (!$isPublished) {
            return $currentStatus;
        }

        if ($availableQuantity <= 0.0001) {
            return self::STATUS_SOLD_OUT;
        }

        return $currentStatus === self::STATUS_SOLD_OUT ? self::STATUS_ACTIVE : $currentStatus;
    }

    /**
     * Whether a buyer may open this listing at all.
     *
     * SOLD_OUT and EXPIRED stay visible because a buyer holding a link deserves
     * to see why it no longer works, and the status says so honestly. DRAFT,
     * PENDING_REVIEW, PAUSED and CANCELLED are private to the seller: the first
     * two expose prices the market was never offered, the last two expose terms
     * the seller has taken back.
     */
    public function isPubliclyVisible(): bool
    {
        return in_array($this->status, [
            self::STATUS_ACTIVE,
            self::STATUS_SOLD_OUT,
            self::STATUS_EXPIRED,
        ], true);
    }

    /**
     * States in which a business may not put stock in front of the market.
     *
     * A suspension is a decision about a business, and a decision that leaves
     * its already-published offers on the shelf has been recorded but not
     * applied. REJECTED is included because the platform has said this business
     * may not trade; PENDING_KYC is not, because a business can only reach the
     * listing screens after it has registered.
     */
    public const NON_TRADING_SELLER_STATUSES = [
        Organization::STATUS_SUSPENDED,
        Organization::STATUS_REJECTED,
    ];

    /**
     * Listings whose seller is still allowed to trade.
     */
    public function scopeTradable($query)
    {
        return $query->whereHas(
            'seller',
            fn ($seller) => $seller->whereNotIn('status', self::NON_TRADING_SELLER_STATUSES)
        );
    }

    public function sellerMayTrade(): bool
    {
        $seller = $this->seller;

        return $seller !== null
            && !in_array($seller->status, self::NON_TRADING_SELLER_STATUSES, true);
    }

    protected $fillable = [
        'seller_organization_id',
        'warehouse_id',
        'product_variant_id',
        'category_id',
        'title_ar',
        'title_en',
        'description_ar',
        'description_en',
        'opportunity_reason',
        'opportunity_reason_notes',
        'status',
        'show_seller_identity',
        'min_order_quantity',
        'max_order_quantity',
        'total_quantity',
        'available_quantity',
        'reserved_quantity',
        'sold_quantity',
        'unit',
        'unit_price',
        'currency_code',
        'production_date',
        'expiry_date',
        'storage_condition',
        'delivery_terms',
        'inspection_window_hours',
        'published_at',
        'expires_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'min_order_quantity' => 'decimal:2',
            'max_order_quantity' => 'decimal:2',
            'total_quantity' => 'decimal:2',
            'available_quantity' => 'decimal:2',
            'reserved_quantity' => 'decimal:2',
            'sold_quantity' => 'decimal:2',
            'unit_price' => 'decimal:4',
            'production_date' => 'date',
            'expiry_date' => 'date',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'seller_organization_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OfferItem::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function demandMatches(): HasMany
    {
        return $this->hasMany(DemandMatch::class);
    }

    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    /**
     * Interest recorded by other businesses. Counted on read, never stored as a
     * number that could drift from the rows behind it.
     */
    public function likes(): HasMany
    {
        return $this->hasMany(OfferLike::class);
    }

    /** Public questions and remarks, in every moderation state. */
    public function comments(): HasMany
    {
        return $this->hasMany(OfferComment::class);
    }
}
