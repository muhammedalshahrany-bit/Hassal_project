<?php

namespace App\Core\Http\Middleware;

use App\Core\Models\AuditLog;
use App\Core\Tenant\TenantContext;
use App\Core\Support\SensitivePayload;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditLogMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Record mutating actions
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $user = $request->user();
            $orgId = TenantContext::id();

            // What the row may hold. `describably()` used to be a four-name
            // `except()` here, which matched only top-level keys — so a nested
            // password, a `*_client_secret`, and the bearer token in a response
            // body all reached the table. The rules and the reasoning are in
            // App\Core\Support\SensitivePayload.
            $payload = SensitivePayload::fromRequest($request);

            // The outcome, recorded next to the intent. Without it a row says
            // "someone tried to delete offer 41" and a reader cannot tell
            // whether it happened — which turns a trail into a wish list. It is
            // the response's own status, not a guess from the request.
            $payload['_response_status'] = $response->getStatusCode();

            try {
                AuditLog::create([
                    'organization_id' => $orgId,
                    'user_id' => $user?->id,
                    'action' => $request->method() . ' ' . $request->path(),
                    'auditable_type' => null,
                    'auditable_id' => null,
                    'ip_address' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 255),
                    'old_values' => null,
                    'new_values' => $payload,
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // Do not fail the request if audit log write fails
                report($e);
            }
        }

        return $response;
    }
}
