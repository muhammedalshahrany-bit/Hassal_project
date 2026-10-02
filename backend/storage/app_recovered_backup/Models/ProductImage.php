<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One uploaded photograph of a product.
 *
 * The URL is derived from disk + path at read time rather than stored: a
 * deployment can move its storage or change its host without rewriting rows,
 * and a row cannot point at a URL that no longer serves anything while still
 * claiming to hold a file.
 */
class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'organization_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'sort_order',
        'is_cover',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
            'is_cover' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Where this file is served from, or null when the file is not on disk.
     *
     * A missing file answers null rather than a plausible URL: a listing that
     * renders a broken image tells the seller nothing is wrong, while a null
     * is something the client can label honestly.
     */
    public function url(): ?string
    {
        $disk = Storage::disk($this->disk);

        return $disk->exists($this->path) ? $disk->url($this->path) : null;
    }
}
