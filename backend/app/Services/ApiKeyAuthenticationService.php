<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ApiKeyAuthenticationService
{
    /**
     * Generates a new API Key with a public Key ID and secret key (shown only once).
     */
    public function generate(Organization $organization, string $name, array $scopes = ['*'], ?Carbon $expiresAt = null): array
    {
        $keyId = 'has_live_' . Str::random(24);
        $secret = 'sk_' . Str::random(36);
        $secretHash = hash('sha256', $secret);

        $apiKey = ApiKey::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'key_id' => $keyId,
            'key_secret_hash' => $secretHash,
            'scopes' => $scopes,
            'status' => ApiKey::STATUS_ACTIVE,
            'expires_at' => $expiresAt,
        ]);

        return [
            'api_key' => $apiKey,
            'key_id' => $keyId,
            'secret' => $secret, // Full secret is returned only upon generation
            'token' => "{$keyId}:{$secret}",
        ];
    }

    /**
     * Authenticates an API key string "keyId:secret".
     */
    public function authenticate(string $token): ?ApiKey
    {
        if (!str_contains($token, ':')) {
            return null;
        }

        [$keyId, $secret] = explode(':', $token, 2);

        $apiKey = ApiKey::where('key_id', $keyId)
            ->where('status', ApiKey::STATUS_ACTIVE)
            ->first();

        if (!$apiKey) {
            return null;
        }

        if ($apiKey->expires_at && Carbon::now()->greaterThan($apiKey->expires_at)) {
            $apiKey->update(['status' => ApiKey::STATUS_EXPIRED]);
            return null;
        }

        $computedHash = hash('sha256', $secret);
        if (!hash_equals($apiKey->key_secret_hash, $computedHash)) {
            return null;
        }

        $apiKey->update(['last_used_at' => Carbon::now()]);

        return $apiKey;
    }
}
