<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
            $payload = $this->describably($request->except(['password', 'password_confirmation', 'token', 'secret']));

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

    /**
     * A payload the row can actually hold.
     *
     * The column is JSON, and an uploaded file is not JSON-encodable, so every
     * file upload — a product photograph, a registration certificate — used to
     * throw here and be swallowed by the catch below. The request succeeded and
     * the audit trail recorded that nobody did anything, which is the one
     * outcome an audit log must never produce silently.
     *
     * What replaces the file is what the log can answer later: that a named
     * person attached a named file of a given size and type. The bytes
     * themselves are not copied into the trail.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function describably(array $payload): array
    {
        $described = [];

        foreach ($payload as $key => $value) {
            $described[$key] = match (true) {
                $value instanceof UploadedFile => array_filter([
                    'file'      => $value->getClientOriginalName(),
                    'mime_type' => $value->getMimeType(),
                    'size'      => $value->getSize(),
                ], fn ($v) => $v !== null),
                is_array($value) => $this->describably($value),
                // Naming the object is honest; casting one that has no
                // __toString() would throw and lose the row again.
                is_object($value) => method_exists($value, '__toString')
                    ? (string) $value
                    : 'object:' . class_basename($value),
                default => $value,
            };
        }

        return $described;
    }
}
