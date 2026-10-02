<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerAccount extends Model
{
    use HasFactory;

    public const TYPE_ASSET = 'ASSET';
    public const TYPE_LIABILITY = 'LIABILITY';
    public const TYPE_EQUITY = 'EQUITY';
    public const TYPE_REVENUE = 'REVENUE';
    public const TYPE_EXPENSE = 'EXPENSE';

    protected $fillable = [
        'account_code',
        'name_ar',
        'name_en',
        'type',
        'organization_id',
        'currency_code',
        'balance',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LedgerLine::class);
    }
}
