<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_SUCCEEDED = 'SUCCEEDED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_REFUNDED = 'REFUNDED';

    public const PROVIDER_BANK_TRANSFER = 'BANK_TRANSFER';
    public const PROVIDER_CASH_ON_DELIVERY = 'CASH_ON_DELIVERY';
    public const PROVIDER_ESCROW_WALLET = 'ESCROW_WALLET';
    public const PROVIDER_ONLINE_GATEWAY = 'ONLINE_GATEWAY';

    protected $fillable = [
        'payment_number',
        'deal_id',
        'organization_id',
        'amount',
        'currency_code',
        'exchange_rate_snapshot',
        'provider',
        'status',
        'transaction_reference',
        'bank_receipt_url',
        'payment_details',
        'verified_by_user_id',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'exchange_rate_snapshot' => 'decimal:6',
            'payment_details' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function verifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
