<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasFactory;

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
