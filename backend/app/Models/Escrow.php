<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Escrow extends Model
{
    use HasFactory;

    public const STATUS_HELD = 'HELD';
    public const STATUS_RELEASED = 'RELEASED';
    public const STATUS_REFUNDED = 'REFUNDED';
    public const STATUS_PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';

    protected $fillable = [
        'escrow_number',
        'deal_id',
        'buyer_organization_id',
        'seller_organization_id',
        'gross_amount',
        'platform_fee_amount',
        'seller_net_amount',
        'currency_code',
        'status',
        'funded_at',
        'released_at',
        'refunded_at',
        'release_notes',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:4',
            'platform_fee_amount' => 'decimal:4',
            'seller_net_amount' => 'decimal:4',
            'funded_at' => 'datetime',
            'released_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function buyerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'buyer_organization_id');
    }

    public function sellerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'seller_organization_id');
    }
}
