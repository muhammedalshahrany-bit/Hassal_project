<?php

namespace App\Core\Http\Middleware;

use App\Core\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account that has been suspended must stop working immediately.
 *
 * Until now `users.status` was read at exactly one moment: login. A token
 * minted while the account was active kept authenticating after an operator
 * pressed "إيقاف الحساب" — the earlier request was proven against this
 * codebase's own running API, where a suspended account still read its offers
 * and reached validation on a write. The button changed a column and nothing
 * else, which is the definition of a formality: the platform appeared to have
 * a kill switch and did not.
 *
 * This closes the gap where the other one already lives. A suspended
 * *organization* is refused per request by `ResolveOrganizationContext`; a
 * suspended *person* is refused here, before tenant resolution, so no screen or
 * service has to remember to check.
 *
 * Deliberately narrow: it says nothing about authorization. `active` is not a
 * permission; it is the precondition for holding a session at all.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Unauthenticated traffic is untouched: public browsing is allowed, and
        // `auth:sanctum` produces its own 401 for the routes that need a body.
        if ($user === null) {
            return $next($request);
        }

        if ($user->status !== 'active') {
            return ApiResponse::error(
                'حسابك غير نشط حالياً؛ تواصل مع إدارة المنصة.',
                'ACCOUNT_NOT_ACTIVE',
                Response::HTTP_FORBIDDEN,
                ['status' => $user->status]
            );
        }

        return $next($request);
    }
}
