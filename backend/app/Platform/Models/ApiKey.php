<?php

namespace App\Platform\Models;

use App\Organizations\Models\Organization;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_REVOKED = 'REVOKED';
    public const STATUS_EXPIRED = 'EXPIRED';

    protected $fillable = [
        'organization_id',
        'name',
        'key_id',
        'key_secret_hash',
        'scopes',
        'status',
        'last_used_at',
        'expires_at',
    ];

    protected $hidden = [
        'key_secret_hash',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes ?? [];
        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }
}
