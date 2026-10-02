<?php

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasFactory;

    /**
     * Whether this three-letter code names a currency the platform currently
     * trades in.
     *
     * Every door that holds money asks this before storing it, and they all ask
     * it the same way: an inactive currency still exists as a row — deactivating
     * one is a switch an operator can throw — so "is in the table" is not the
     * question. Left to each caller, the third variant of this check will read
     * `where('code', …)` or forget the active half, and a listing priced in a
     * retired currency is exactly the kind of row that only shows up in a
     * settlement months later.
     */
    public static function isActive(string $isoCode): bool
    {
        return static::query()
            ->where('iso_code', $isoCode)
            ->where('is_active', true)
            ->exists();
    }

    protected $fillable = [
        'iso_code',
        'name_ar',
        'name_en',
        'symbol',
        'decimal_places',
        'exchange_rate_to_base',
        'rate_source',
        'rate_updated_at',
        'rate_updated_by_user_id',
        'is_active',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'exchange_rate_to_base' => 'decimal:6',
            'rate_updated_at' => 'datetime',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
