<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'type', // string, integer, decimal, boolean, json, array
        'group',
        'description_ar',
        'description_en',
        'default_value',
        'is_editable',
        'is_public',
        'validation_rules',
        'organization_id',
    ];

    protected function casts(): array
    {
        return [
            'is_editable' => 'boolean',
            'is_public' => 'boolean',
            'validation_rules' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function getTypedValueAttribute(): mixed
    {
        $val = $this->value ?? $this->default_value;
        if ($val === null) return null;

        return match ($this->type) {
            'boolean' => filter_var($val, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int)$val,
            'decimal', 'float' => (float)$val,
            'json', 'array' => json_decode($val, true) ?? [],
            default => (string)$val,
        };
    }
}
