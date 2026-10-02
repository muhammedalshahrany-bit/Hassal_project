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
     * Whether this listing's own validity has ended.
     *
     * The date, not the label. Nothing sweeps `status` to EXPIRED when
     * `expires_at` passes: the only writer of that label is
     * `InventoryReservationService`, and it reaches for the row *after* a
     * reservation has already failed. A listing can therefore sit `ACTIVE` past
     * its own ending for as long as nobody tries to buy from it — which is
     * precisely the state a buyer's request would be filed against.
     *
     * So every question of the form «can a buyer still act on this» asks here,
     * rather than comparing two facts the row already holds and disagreeing
     * about which one wins.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThan($this->expires_at);
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

    /**
     * The category, read from the product the listing names.
     *
     * `offers` used to carry its own `category_id`, and a product that was
     * reclassified left its shelves behind in the category it had already left.
     * The key is gone; the *answer* is not. Reaching it through the variant costs
     * one step on a chain every listing was already walking, and clients that
     * filter, group or display `category_id` see no change at all — which is the
     * point of normalizing a copy away rather than renaming a fact.
     *
     * Read-only by construction: nothing is written here, so nothing can disagree
     * with the product. (Mass assignment is closed separately, by this column's
     * absence from `$fillable`.)
     */
    public function getCategoryIdAttribute(): ?int
    {
        $categoryId = $this->variant?->product?->category_id;

        return $categoryId === null ? null : (int) $categoryId;
    }

    /**
     * What a request may set on an offer.
     *
     * Four fields were removed from this list, and they are the four that must
     * not be: `status`, `available_quantity`, `reserved_quantity` and
     * `sold_quantity`.
     *
     * Their absence is not a tidy-up. `PUT /marketplace/offers/{id}` passes its
     * validated array to `update()` in bulk, and a field that is fillable is a
     * field that arrives from the wire — so a seller could send
     * `{"sold_quantity": 500}` against their own listing and have the platform
     * publish it as history, or send `{"status": "ACTIVE"}` on a draft and skip
     * moderation. Worse, the reservation service reads
     * `available_quantity` to decide whether stock exists: a client-set number
     * there is a way to oversell inventory that two buyers already hold.
     *
     * Those four are now written only where the platform itself decides —
     * `InventoryReservationService`, `DealStateMachineService`,
     * `InventoryMovementController`, and the moderation and status routes — via
     * `forceFill()`, which says in the calling code that this is a server-side
     * transition and not a field someone asked for. Commercial terms a seller
     * genuinely owns (price, `total_quantity`, titles, dates) remain fillable:
     * `total_quantity` is a declared stock figure, and the controller
     * recomputes `available_quantity` from it, so the client states the intent
     * and the server derives the consequence.
     */
    /**
     * The derived answers an offer carries in its own JSON.
     *
     * `category_id` used to be a column, so it appeared in every response that
     * serialized the model straight — `POST /marketplace/offers`, the status
     * route, the moderation decisions. The column is gone (see
     * `getCategoryIdAttribute()`), and without this line those five responses
     * would silently stop answering a question clients still ask, while the
     * resource-shaped endpoints kept answering it correctly. That is the worst
     * possible way for one key to split: the same offer, described two ways.
     *
     * The cost is the one the accessor documents: a serialization of an offer
     * whose `variant.product` was not eager loaded asks for it. Every caller
     * listed above either loads the chain or is a single row.
     */
    protected $appends = ['category_id'];

    protected $fillable = [
        'seller_organization_id',
        'warehouse_id',
        'product_variant_id',
        'title_ar',
        'title_en',
        'description_ar',
        'description_en',
        'opportunity_reason',
        'opportunity_reason_notes',
        'show_seller_identity',
        'min_order_quantity',
        'max_order_quantity',
        'total_quantity',
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
            'featured_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Listings the platform has pinned for the marketplace carousel.
     *
     * This says *pinned*, nothing more. Whether the pin is still worth showing
     * is a question about the listing's own state — stock, status, expiry, the
     * seller's standing — and `OfferController` answers it with the same chain
     * the public index uses, so a promotion can never outlive the thing it
     * promotes.
     */
    public function scopeFeatured($query)
    {
        return $query->whereNotNull('featured_at')->orderByDesc('featured_at');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'seller_organization_id');
    }

    /**
     * Who pinned this listing. Nullable twice over: most listings were never
     * pinned, and a pin outlives the staff account that placed it.
     */
    public function featuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'featured_by');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
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
