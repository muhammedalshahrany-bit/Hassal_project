<?php

namespace App\Organizations\Models;

use App\Identity\Models\User;
use App\Marketplace\Models\Offer;
use App\Marketplace\Models\Demand;
use App\Marketplace\Models\Quotation;
use App\Trading\Models\Deal;
use App\Trading\Models\LedgerAccount;
use App\Inventory\Models\Warehouse;
use App\Platform\Models\ApiKey;
use App\Platform\Models\Webhook;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    // Verification status constants
    public const STATUS_PENDING    = 'pending_kyc';
    public const STATUS_VERIFIED   = 'verified';
    public const STATUS_SUSPENDED  = 'suspended';
    public const STATUS_REJECTED   = 'rejected';

    // Organization type constants
    public const TYPE_BUYER        = 'buyer';
    public const TYPE_SELLER       = 'seller';
    public const TYPE_INTERMEDIARY = 'intermediary';
    public const TYPE_ADMIN        = 'admin';

    protected $fillable = [
        'name_ar',
        'name_en',
        'trade_name_ar',
        'trade_name_en',
        'type',
        'country_code',
        'city',
        'website',
        'phone',
        'email',
        'commercial_registration_number',
        'vat_number',
        'status',
        'credit_limit',
        'rating',
        'kyc_verified_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'rating' => 'decimal:2',
            'kyc_verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /**
     * The seats on this company's roster that are currently filled.
     *
     * Asked as a query, not a collection, because the roster is read in three
     * shapes — the list a member sees, the headcount a profile prints, the
     * user ids a notice goes to — and each of them is the same rule about
     * `is_active`. A removed person's row stays for the audit trail; an answer
     * built from the rows alone would keep telling a departed employee's
     * handset about a business it no longer works for.
     *
     * @return HasMany<OrganizationMembership>
     */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('is_active', true);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_memberships')
            ->withPivot('role', 'is_active', 'joined_at')
            ->withTimestamps();
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class, 'seller_organization_id');
    }

    public function buyerDeals(): HasMany
    {
        return $this->hasMany(Deal::class, 'buyer_organization_id');
    }

    public function sellerDeals(): HasMany
    {
        return $this->hasMany(Deal::class, 'seller_organization_id');
    }

    public function demands(): HasMany
    {
        return $this->hasMany(Demand::class, 'buyer_organization_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'seller_organization_id');
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    public function ledgerAccounts(): HasMany
    {
        return $this->hasMany(LedgerAccount::class);
    }

    /**
     * The legal papers this business filed, and what was decided on each.
     *
     * A verification verdict is the organization's own `status` column; these
     * rows are the evidence a reviewer had in front of them when it was set.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(OrganizationDocument::class);
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }
}
