<?php

namespace App\Trading\Models;

use App\Identity\Models\User;
use App\Organizations\Models\Organization;
use App\Marketplace\Models\Offer;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reservation extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_CONVERTED_TO_DEAL = 'CONVERTED_TO_DEAL';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'reservation_number',
        'offer_id',
        'buyer_organization_id',
        'buyer_user_id',
        'quantity',
        'unit_price',
        'currency_code',
        'total_amount',
        'delivery_preference',
        'status',
        'expires_at',
        'converted_at',
        'cancelled_at',
        'cancellation_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'expires_at' => 'datetime',
            'converted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function buyerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'buyer_organization_id');
    }

    public function buyerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function deal(): HasOne
    {
        return $this->hasOne(Deal::class);
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_ACTIVE && now()->greaterThan($this->expires_at);
    }
}
