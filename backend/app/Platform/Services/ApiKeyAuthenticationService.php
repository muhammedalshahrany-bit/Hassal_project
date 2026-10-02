<?php

namespace App\Platform\Services;

use App\Platform\Models\ApiKey;
use App\Organizations\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class ApiKeyAuthenticationService
{
    /**
     * The middleware alias a route must carry for an issued key to open it.
     * No route in this application does yet, which is what
     * {@see self::acceptedByAnyRoute()} reports.
     */
    public const MIDDLEWARE_ALIAS = 'api.key';

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

    /**
     * Does any registered route actually accept a key?
     *
     * `authenticate()` above is correct code with no caller on a request path:
     * no guard and no middleware in this application resolves a key into an
     * authenticated organization. So a key issued today opens nothing, and an
     * integrations screen that lets a business hand one to a partner is telling
     * that business its integration works.
     *
     * This asks the router rather than restating a constant, because a constant
     * would be wrong in both directions — it would still claim "not ready" the
     * week someone attaches the middleware, and it would have to be remembered.
     * The screen reads are driven off the route table, so they correct themselves
     * the moment key authentication exists.
     */
    public function acceptedByAnyRoute(): bool
    {
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $attached) {
                if (!is_string($attached)) {
                    continue;
                }

                if ($attached === self::MIDDLEWARE_ALIAS
                    || str_starts_with($attached, self::MIDDLEWARE_ALIAS . ':')) {
                    return true;
                }
            }
        }

        return false;
    }
}
