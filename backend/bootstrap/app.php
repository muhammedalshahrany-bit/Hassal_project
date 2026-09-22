<?php

use App\Http\Middleware\AuditLogMiddleware;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureVerifiedOrganization;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\LocalizationMiddleware;
use App\Http\Middleware\ResolveOrganizationContext;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // `throttle:api` on the whole group. Laravel does not install a default
        // ceiling for an api group, and this platform had none either: the only
        // limiters were four route-level ones on the auth endpoints, so an
        // authenticated account could issue unlimited requests against every
        // other path. The ceilings themselves are settings — see
        // AppServiceProvider::defineRequestCeilings().
        $middleware->throttleApi();

        // Order is load-bearing. `SecurityHeaders` sits outside
        // `EnsureAccountIsActive` so the refusal a suspended account receives
        // still carries the same headers as every other answer — a rejected
        // request that reaches a browser without `nosniff` is the one place a
        // header policy would otherwise have a hole.
        $middleware->api(append: [
            LocalizationMiddleware::class,
            SecurityHeaders::class,
            EnsureAccountIsActive::class,
        ]);

        $middleware->redirectGuestsTo(null);

        $middleware->alias([
            'tenant' => ResolveOrganizationContext::class,
            // A tenant whose legal record has not been reviewed may browse and
            // file papers, but not trade. Attached per route group.
            'verified_tenant' => EnsureVerifiedOrganization::class,
            'idempotency' => IdempotencyMiddleware::class,
            'audit' => AuditLogMiddleware::class,
            'feature' => EnsureFeatureEnabled::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A refused request is still an API answer. Laravel's default body for
        // this is `{"message": "Too Many Attempts."}`, which every client here
        // parses through the envelope and would read as an unknown failure — and
        // `Retry-After` is the one piece of information an operator and a
        // partner both need.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

                return ApiResponse::error(
                    'طلبات كثيرة جداً؛ تجاوزت الحد المسموح في الدقيقة. '
                        . ($retryAfter ? "أعد المحاولة بعد {$retryAfter} ثانية." : 'أعد المحاولة بعد قليل.'),
                    'TOO_MANY_REQUESTS',
                    429,
                    ['retry_after' => $retryAfter],
                    $e->getHeaders()
                );
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return ApiResponse::error(
                    'Validation failed',
                    'VALIDATION_FAILED',
                    422,
                    $e->errors()
                );
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return ApiResponse::error(
                    'Unauthenticated or invalid token',
                    'UNAUTHENTICATED',
                    401
                );
            }
        });

        // Spatie's permission failure is registered ahead of the generic
        // authorization renderer below, because it is the more specific
        // exception: both end up as 403, but only one of them knows which
        // capability was missing. "Forbidden" cannot be acted on; "you need
        // offers.moderate" can.
        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return ApiResponse::error(
                    'صلاحية غير كافية لهذا الإجراء',
                    'PERMISSION_DENIED',
                    403,
                    ['required_permission' => $e->getRequiredPermissions()[0] ?? null]
                );
            }
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return ApiResponse::error(
                    $e->getMessage() ?: 'This action is unauthorized',
                    'FORBIDDEN',
                    403
                );
            }
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return ApiResponse::error(
                    'Resource or route not found',
                    'NOT_FOUND',
                    404
                );
            }
        });
    })->create();
