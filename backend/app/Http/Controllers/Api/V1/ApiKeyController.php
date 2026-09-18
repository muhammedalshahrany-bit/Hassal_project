<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Services\ApiKeyAuthenticationService;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyController extends Controller
{
    public function __construct(
        protected ApiKeyAuthenticationService $apiKeyService
    ) {}

    public function index(): JsonResponse
    {
        $orgId = TenantContext::id();
        if (!$orgId) {
            return ApiResponse::error('Active organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $keys = ApiKey::where('organization_id', $orgId)->orderBy('created_at', 'desc')->get();
        return ApiResponse::success($keys, 'API Keys retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();
        if (!$orgId) {
            return ApiResponse::error('Active organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $org = Organization::findOrFail($orgId);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['nullable', 'array'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $expiresAt = !empty($validated['expires_in_days']) ? Carbon::now()->addDays($validated['expires_in_days']) : null;
        $scopes = $validated['scopes'] ?? ['*'];

        $result = $this->apiKeyService->generate($org, $validated['name'], $scopes, $expiresAt);

        return ApiResponse::success($result, 'API Key generated successfully. Please copy the secret key now; it will not be shown again.', Response::HTTP_CREATED);
    }

    public function revoke(int $id): JsonResponse
    {
        $orgId = TenantContext::id();
        $key = ApiKey::where('organization_id', $orgId)->findOrFail($id);

        $key->update(['status' => ApiKey::STATUS_REVOKED]);

        return ApiResponse::success($key, 'API Key revoked successfully');
    }
}
