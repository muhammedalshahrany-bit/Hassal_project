<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\FeatureFlag;
use App\Services\FeatureFlagService;
use App\Support\FlagCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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

    /**
     * Toggle one capability.
     *
     * A key the catalog does not define is refused rather than created:
     * `isEnabled()` fails closed on an unknown key, so such a switch could not
     * change any behaviour, and the insert itself would abort on the NOT NULL
     * `name_ar` — a 500 where a 422 belongs. The catalog is the list of flags
     * that gate real backend paths.
     */
    public function toggle(Request $request, string $key): JsonResponse
    {
        $catalog = array_column(FlagCatalog::defaults(), 'key');

        if (!in_array($key, $catalog, true)) {
            return ApiResponse::error(
                "لا يوجد capability بهذا الاسم: {$key}",
                'FLAG_NOT_IN_CATALOG',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['known_flags' => $catalog]
            );
        }

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
