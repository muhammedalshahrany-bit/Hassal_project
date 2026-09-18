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

    // Constants for statuses
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PENDING_REVIEW = 'PENDING_REVIEW';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_PAUSED = 'PAUSED';
    public const STATUS_FULLY_RESERVED = 'FULLY_RESERVED';
    public const STATUS_SOLD_OUT = 'SOLD_OUT';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_ARCHIVED = 'ARCHIVED';

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
        'min_order_quantity',
        'max_order_quantity',
        'total_quantity',
        'available_quantity',
        'reserved_quantity',
        'sold_quantity',
        'unit',
        'unit_price',
        'market_reference_price',
        'discount_percentage',
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
            'market_reference_price' => 'decimal:4',
            'discount_percentage' => 'decimal:2',
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
}
