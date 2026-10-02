<?php

namespace App\Catalog\Models;

use App\Organizations\Models\Organization;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'category_id',
        'brand_id',
        'sku',
        'barcode',
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'unit',
        'package_size',
        'temperature_class',
        'shelf_life_days',
        'image_url',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'shelf_life_days' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Which products a reader may see.
     *
     * `organization_id` is nullable, and that null is the whole design: a
     * product with no owner is a platform-level reference entry that any
     * business may list against, while a product with an owner is that
     * business' own SKU book — its packaging conventions, its internal codes.
     *
     * Before this rule existed the endpoints answered with every row in the
     * table, so a business that opened the catalog saw another business'
     * inventory naming in it. Returning your own shelf plus the public
     * vocabulary is the same result set for a solo platform user and no longer
     * a window into a competitor.
     *
     * A scope rather than a controller helper because the read is the model's
     * own business, and two endpoints — the listing and one product's variants
     * — must not drift into two different definitions of "yours".
     */
    public function scopeVisibleTo(Builder $query, ?int $organizationId, bool $isPlatformStaff): Builder
    {
        if ($isPlatformStaff) {
            return $query;
        }

        return $query->where(function (Builder $owned) use ($organizationId) {
            $owned->whereNull('organization_id');

            if ($organizationId !== null) {
                $owned->orWhere('organization_id', $organizationId);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Photographed files, ordered as the seller arranged them.
     *
     * This relation, not the single `image_url` column, is what the listing
     * reads: `image_url` has no upload path and can only hold one string.
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }
}
