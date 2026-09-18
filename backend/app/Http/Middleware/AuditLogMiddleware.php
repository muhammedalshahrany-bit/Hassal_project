<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Services\TenantContext;
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

            // Sanitize payload (remove passwords, tokens)
            $payload = $request->except(['password', 'password_confirmation', 'token', 'secret']);

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
