<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\FeatureFlag;
use App\Services\FeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeatureFlagController extends Controller
{
    public function __construct(
        protected FeatureFlagService $featureFlagService
    ) {}

    public function index(): JsonResponse
    {
        $flags = FeatureFlag::all();
        return ApiResponse::success($flags, 'Feature flags retrieved successfully');
    }

    public function toggle(Request $request, string $key): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'rules_json' => ['nullable', 'array'],
        ]);

        $flag = $this->featureFlagService->setFlag(
            $key,
            (bool)$validated['is_enabled'],
            $validated['rules_json'] ?? null
        );

        return ApiResponse::success($flag, "Feature flag '{$key}' updated successfully");
    }
}
