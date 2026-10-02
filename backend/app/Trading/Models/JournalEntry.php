<?php

namespace App\Trading\Models;

use App\Identity\Models\User;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'entry_number',
        'transaction_reference',
        'currency_code',
        'exchange_rate_to_base',
        'description',
        'entity_type',
        'entity_id',
        'created_by_user_id',
        'total_debit',
        'total_credit',
        'posted_at',
        'is_reversal',
        'reversal_of_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'exchange_rate_to_base' => 'decimal:6',
            'total_debit' => 'decimal:4',
            'total_credit' => 'decimal:4',
            'posted_at' => 'datetime',
            'is_reversal' => 'boolean',
        ];
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LedgerLine::class);
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_of_entry_id');
    }

    public function isBalanced(): bool
    {
        return abs((float)$this->total_debit - (float)$this->total_credit) < 0.0001;
    }
}
