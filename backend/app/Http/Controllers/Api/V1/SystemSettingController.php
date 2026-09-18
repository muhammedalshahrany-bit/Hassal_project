<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\SystemSetting;
use App\Services\SystemSettingsService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SystemSettingController extends Controller
{
    public function __construct(
        protected SystemSettingsService $settingsService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();
        $query = SystemSetting::query();

        if (!$request->user()->hasRole('SuperAdmin')) {
            $query->where(function ($q) use ($orgId) {
                $q->where('is_public', true);
                if ($orgId) $q->orWhere('organization_id', $orgId);
            });
        }

        if ($request->filled('group')) {
            $query->where('group', $request->input('group'));
        }

        $settings = $query->get();
        return ApiResponse::success($settings, 'Settings retrieved successfully');
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string'],
            'value' => ['required'],
            'group' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
        ]);

        $orgId = $request->user()->hasRole('SuperAdmin') ? null : TenantContext::id();

        $setting = $this->settingsService->set(
            $validated['key'],
            $validated['value'],
            $validated['type'] ?? 'string',
            $validated['group'] ?? 'platform',
            null,
            null,
            $orgId
        );

        return ApiResponse::success($setting, 'Setting updated successfully');
    }
}
