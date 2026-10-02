<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeatureFlag extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'is_enabled',
        'rules_json',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'rules_json' => 'array',
        ];
    }
}
