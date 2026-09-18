<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\BootstrapService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function __construct(
        protected BootstrapService $bootstrapService
    ) {}

    /**
     * Client initialization endpoint for Flutter Mobile and Web.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $orgId = TenantContext::id();

        $payload = $this->bootstrapService->getBootstrapPayload($user, $orgId);

        return ApiResponse::success($payload, 'Application bootstrap data loaded successfully');
    }
}
