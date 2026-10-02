<?php

namespace App\Inventory\Models;

use App\Identity\Models\User;
use App\Organizations\Models\Organization;
use App\Catalog\Models\ProductVariant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    use HasFactory;

    public const TYPE_IN = 'IN';
    public const TYPE_RESERVED = 'RESERVED';
    public const TYPE_RELEASED = 'RELEASED';
    public const TYPE_SOLD = 'SOLD';
    public const TYPE_ADJUSTED = 'ADJUSTED';
    public const TYPE_RETURNED = 'RETURNED';

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'product_variant_id',
        'offer_id',
        'deal_id',
        'user_id',
        'type',
        'quantity',
        'previous_quantity',
        'new_quantity',
        'reference_type',
        'reference_id',
        'reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'previous_quantity' => 'decimal:2',
            'new_quantity' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
