<?php

namespace App\Core\Http\Middleware;

use App\Core\Http\ApiResponse;
use App\Organizations\Models\Organization;
use App\Core\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveOrganizationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        // On an authenticated route the auth middleware has already made the
        // bearer user `$request->user()`. On a hybrid public route it has not,
        // and the default guard is the session one, so the token is resolved
        // explicitly here rather than being silently ignored.
        $user = $request->user() ?: $request->user('sanctum');

        if (!$user) {
            return $next($request);
        }

        $organizationId = $request->header('X-Organization-Id');

        if (!$organizationId) {
            // Fall back to first active membership if available
            $firstMembership = $user->firstActiveMembership();
            if ($firstMembership && $firstMembership->organization) {
                TenantContext::set($firstMembership->organization, $firstMembership);
                $request->attributes->set('organization', $firstMembership->organization);
                $request->attributes->set('membership', $firstMembership);
            }
            return $next($request);
        }

        $organization = Organization::find($organizationId);

        if (!$organization) {
            return ApiResponse::error(
                'Organization not found',
                'ORGANIZATION_NOT_FOUND',
                Response::HTTP_NOT_FOUND
            );
        }

        if ($organization->status === 'suspended' || $organization->status === 'rejected') {
            return ApiResponse::error(
                'Organization account is suspended or inactive',
                'ORGANIZATION_SUSPENDED',
                Response::HTTP_FORBIDDEN
            );
        }

        // Check if user is super-admin or has active membership
        $isSuperAdmin = $user->isPlatformOperator();
        $membership = $user->firstActiveMembership($organizationId);

        if (!$isSuperAdmin && !$membership) {
            return ApiResponse::error(
                'You do not have active access to this organization',
                'ORGANIZATION_ACCESS_DENIED',
                Response::HTTP_FORBIDDEN
            );
        }

        TenantContext::set($organization, $membership);
        $request->attributes->set('organization', $organization);
        $request->attributes->set('membership', $membership);

        $response = $next($request);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        TenantContext::clear();
    }
}
