<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequest extends Model
{
    use HasFactory;

    public const STATUS_REQUESTED = 'REQUESTED';
    public const STATUS_SELLER_REVIEW = 'SELLER_REVIEW';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_PROPOSED = 'PROPOSED';
    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'purchase_request_number',
        'demand_id',
        'demand_match_id',
        'offer_id',
        'buyer_organization_id',
        'seller_organization_id',
        'buyer_user_id',
        'seller_user_id',
        'requested_quantity',
        'unit',
        'requested_price',
        'currency_code',
        'delivery_preference',
        'status',
        'notes',
        'proposed_terms',
    ];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:2',
            'requested_price' => 'decimal:4',
            'proposed_terms' => 'array',
        ];
    }

    public function demand(): BelongsTo
    {
        return $this->belongsTo(Demand::class);
    }

    public function demandMatch(): BelongsTo
    {
        return $this->belongsTo(DemandMatch::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
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
}
