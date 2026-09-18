<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Demand extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'OPEN';
    public const STATUS_QUOTED = 'QUOTED';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_CLOSED = 'CLOSED';
    public const STATUS_EXPIRED = 'EXPIRED';

    protected $fillable = [
        'demand_number',
        'buyer_organization_id',
        'buyer_user_id',
        'category_id',
        'title_ar',
        'title_en',
        'description',
        'target_quantity',
        'unit',
        'target_unit_price',
        'currency_code',
        'target_delivery_date',
        'delivery_city',
        'delivery_latitude',
        'delivery_longitude',
        'special_requirements',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'target_quantity' => 'decimal:2',
            'target_unit_price' => 'decimal:4',
            'target_delivery_date' => 'date',
            'delivery_latitude' => 'decimal:7',
            'delivery_longitude' => 'decimal:7',
            'expires_at' => 'datetime',
        ];
    }

    public function buyerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'buyer_organization_id');
    }

    public function buyerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(DemandMatch::class);
    }

    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }
}
