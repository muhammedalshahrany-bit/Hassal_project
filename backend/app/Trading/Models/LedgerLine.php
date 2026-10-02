<?php

namespace App\Trading\Models;

use App\Organizations\Models\Organization;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_entry_id',
        'ledger_account_id',
        'organization_id',
        'debit_amount',
        'credit_amount',
        'memo',
    ];

    protected function casts(): array
    {
        return [
            'debit_amount' => 'decimal:4',
            'credit_amount' => 'decimal:4',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
