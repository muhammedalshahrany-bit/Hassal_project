<?php

namespace App\Platform\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Http\ApiResponse;
use App\Platform\Models\ApiKey;
use App\Organizations\Models\Organization;
use App\Platform\Services\ApiKeyAuthenticationService;
use App\Core\Tenant\TenantContext;
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

        return ApiResponse::success(
            $keys,
            'API Keys retrieved successfully',
            Response::HTTP_OK,
            [],
            // Read from the route table, not written here by hand: it stays true
            // only as long as some endpoint really accepts a key.
            ['key_authentication_ready' => $this->apiKeyService->acceptedByAnyRoute()],
        );
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

        $ready = $this->apiKeyService->acceptedByAnyRoute();

        return ApiResponse::success(
            $result,
            $ready
                ? 'API Key generated successfully. Please copy the secret key now; it will not be shown again.'
                : 'تم إصدار المفتاح، لكن لا مسار في هذا الخادم يقبل مفتاح API بعد: لن يفتح أي طلب. انسخ السر الآن لأنه لا يُعرض مرة أخرى.',
            Response::HTTP_CREATED,
            [],
            ['key_authentication_ready' => $ready],
        );
    }

    public function revoke(int $id): JsonResponse
    {
        $orgId = TenantContext::id();
        $key = ApiKey::where('organization_id', $orgId)->findOrFail($id);

        $key->update(['status' => ApiKey::STATUS_REVOKED]);

        return ApiResponse::success($key, 'API Key revoked successfully');
    }
}
