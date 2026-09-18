<?php

namespace App\Models;

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

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }
}
