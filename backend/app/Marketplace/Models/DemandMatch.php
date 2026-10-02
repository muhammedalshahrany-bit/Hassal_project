<?php

namespace App\Marketplace\Models;

use App\Trading\Models\PurchaseRequest;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemandMatch extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_USED = 'USED';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_INVALIDATED = 'INVALIDATED';

    protected $fillable = [
        'demand_id',
        'offer_id',
        'buyer_organization_id',
        'seller_organization_id',
        'requested_quantity',
        'match_score',
        'match_reasons',
        'estimated_total',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:2',
            'match_score' => 'decimal:2',
            'estimated_total' => 'decimal:4',
            'match_reasons' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function demand(): BelongsTo
    {
        return $this->belongsTo(Demand::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }
}
