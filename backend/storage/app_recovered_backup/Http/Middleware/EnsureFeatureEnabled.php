<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Services\FeatureFlagService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request whose capability is not installed in this deployment.
 *
 * Feature flags existed in the database and in the admin console but nothing
 * asked them: `FeatureFlagService::isEnabled()` had no callers, so switching
 * the ledger or webhooks off changed nothing a client could observe. A flag that
 * gates no code path is a false control, and the routes below are where a
 * capability is either present or honestly absent.
 */
class EnsureFeatureEnabled
{
    public function __construct(protected FeatureFlagService $flags) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        if (!$this->flags->isEnabled($key, $request->user(), $request->attributes->get('membership')?->organization_id)) {
            return ApiResponse::error(
                'This capability is not enabled in this deployment.',
                'FEATURE_DISABLED',
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        return $next($request);
    }
}
