<?php

namespace App\Marketplace\Models;

use App\Identity\Models\User;
use App\Organizations\Models\Organization;
use App\Trading\Models\Deal;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Quotation extends Model
{
    use HasFactory;

    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_EXPIRED = 'EXPIRED';

    protected $fillable = [
        'quotation_number',
        'demand_id',
        'seller_organization_id',
        'seller_user_id',
        'quoted_quantity',
        'unit_price',
        'currency_code',
        'total_amount',
        'validity_days',
        'notes',
        'status',
        'accepted_at',
        'rejected_at',
        'converted_deal_id',
    ];

    protected function casts(): array
    {
        return [
            'quoted_quantity' => 'decimal:2',
            'unit_price' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'validity_days' => 'integer',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function demand(): BelongsTo
    {
        return $this->belongsTo(Demand::class);
    }

    public function sellerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'seller_organization_id');
    }

    public function sellerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function convertedDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'converted_deal_id');
    }
}
