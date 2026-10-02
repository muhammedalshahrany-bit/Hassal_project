<?php

namespace App\Auth\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Http\ApiResponse;
use App\Auth\Services\BootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function __construct(
        protected BootstrapService $bootstrapService
    ) {}

    /**
     * Bootstrap payload: reference data for anyone, plus the caller's account
     * and current organization when a token rode with the request.
     */
    public function index(Request $request): JsonResponse
    {
        // The payload reads the resolved tenant context itself; passing the
        // organization id in as a second source of the same fact is how the
        // account block and the business block ended up able to name two
        // different companies for one request.
        $payload = $this->bootstrapService->getBootstrapPayload($request->user('sanctum'));

        return ApiResponse::success($payload, 'Application bootstrap data loaded successfully');
    }
}
