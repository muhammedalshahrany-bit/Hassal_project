<?php

namespace App\Core\Http\Middleware;

use App\Core\Http\ApiResponse;
use App\Core\Models\IdempotencyKey;
use App\Core\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only apply idempotency to mutating requests
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return $next($request);
        }

        $idempotencyKey = $request->header('X-Idempotency-Key');

        if (!$idempotencyKey) {
            return $next($request);
        }

        $user = $request->user();
        $userId = $user?->id;
        $orgId = TenantContext::id();

        $requestPayload = $request->getContent();
        $requestHash = hash('sha256', $request->fullUrl() . '|' . $request->method() . '|' . $requestPayload . '|' . $userId);

        $existing = IdempotencyKey::where('key', $idempotencyKey)
            ->where('user_id', $userId)
            ->where('organization_id', $orgId)
            ->where('expires_at', '>', now())
            ->first();

        if ($existing) {
            if ($existing->request_hash !== $requestHash) {
                return ApiResponse::error(
                    'Idempotency key reused with different request parameters',
                    'IDEMPOTENCY_CONFLICT',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            return response($existing->response_body, $existing->response_code)
                ->header('Content-Type', 'application/json')
                ->header('X-Cache-Lookup', 'HIT')
                ->header('X-Idempotency-Replay', 'true');
        }

        $response = $next($request);

        // Store response for successful or standard client status codes
        if ($response->getStatusCode() < 500) {
            IdempotencyKey::updateOrCreate(
                ['key' => $idempotencyKey, 'user_id' => $userId, 'organization_id' => $orgId],
                [
                    'user_id' => $userId,
                    'organization_id' => $orgId,
                    'endpoint' => $request->path(),
                    'request_hash' => $requestHash,
                    'response_code' => $response->getStatusCode(),
                    'response_body' => $response->getContent(),
                    'expires_at' => now()->addHours(24),
                ]
            );
        }

        return $response;
    }
}
