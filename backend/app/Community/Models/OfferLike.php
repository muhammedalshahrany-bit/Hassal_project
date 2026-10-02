<?php

namespace App\Community\Models;

use App\Identity\Models\User;
use App\Organizations\Models\Organization;
use App\Marketplace\Models\Offer;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One business saying "I am interested in this listing".
 *
 * A row is the whole feature. There is no counter column to drift out of step
 * with the rows: `likes_count` is computed with `withCount()` where an offer is
 * serialized, so the number a buyer sees is the number of rows that exist.
 */
class OfferLike extends Model
{
    use HasFactory;

    protected $fillable = [
        'offer_id',
        'user_id',
        'organization_id',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
