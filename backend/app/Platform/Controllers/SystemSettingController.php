<?php

namespace App\Platform\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Http\ApiResponse;
use App\Platform\Models\SystemSetting;
use App\Platform\Support\SettingCatalog;
use App\Platform\Services\SystemSettingsService;
use App\Core\Tenant\TenantContext;
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

        if (!$request->user()->isPlatformOperator()) {
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

    /**
     * Write one setting.
     *
     * Two things the row itself decides, and used to be ignored:
     *
     *   - `is_editable`. The flag is read from the row and honoured; a key
     *     pinned that way can no longer be changed anyway. The catalog seeds
     *     every key editable today, so this is the gate, not a list of keys it
     *     currently blocks.
     *   - `type`. The value is checked against the type the key was declared
     *     with, so `tax.rate` cannot receive the word "نعم" and have it read
     *     back as a number by the pricing service.
     *
     * A key with no row yet is created. `meta.in_catalog` says whether code
     * actually reads it, because a setting nobody reads is a switch that looks
     * live on screen and does nothing.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'value' => ['required'],
            'group' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:50'],
        ]);

        $key = $validated['key'];
        $isPlatform = $request->user()->isPlatformOperator();
        $orgId = TenantContext::id();

        if (!$isPlatform && $orgId === null) {
            return ApiResponse::error(
                'لا يوجد سياق منشأة لهذا الطلب',
                'TENANT_REQUIRED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $rowOrgId = $isPlatform ? null : $orgId;

        $query = SystemSetting::where('key', $key);
        if ($rowOrgId === null) {
            $query->whereNull('organization_id');
        } else {
            $query->where('organization_id', $rowOrgId);
        }
        $existing = $query->first();

        if ($existing !== null && !$existing->is_editable) {
            return ApiResponse::error(
                "هذا الإعداد غير قابل للتعديل: {$key}",
                'SETTING_NOT_EDITABLE',
                Response::HTTP_FORBIDDEN
            );
        }

        $declaredType = $existing?->type ?: ($validated['type'] ?? 'string');

        $problem = self::valueProblem($declaredType, $validated['value']);
        if ($problem !== null) {
            return ApiResponse::error(
                $problem,
                'SETTING_TYPE_MISMATCH',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['value' => [$problem]]
            );
        }

        $setting = $this->settingsService->set(
            $key,
            $validated['value'],
            $declaredType,
            $existing?->group ?: ($validated['group'] ?? 'platform'),
            null,
            null,
            $isPlatform ? null : $orgId
        );

        return ApiResponse::success(
            $setting,
            'Setting updated successfully',
            200,
            [],
            ['in_catalog' => self::inCatalog($key)]
        );
    }

    /**
     * Why this value does not fit the declared type, or null when it does.
     */
    private static function valueProblem(string $type, mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return match ($type) {
            'boolean' => in_array(strtolower($text), ['true', 'false', '1', '0', 'yes', 'no'], true)
                ? null
                : 'قيمة منطقية مطلوبة: true أو false',
            'integer' => preg_match('/^-?\d+$/', $text) === 1 ? null : 'عدد صحيح مطلوب',
            'decimal' => is_numeric($text) ? null : 'قيمة رقمية مطلوبة',
            'json' => is_array($value) || json_decode($text, true) !== null || $text === ''
                ? null
                : 'تركيبة JSON صالحة مطلوبة',
            default => null,
        };
    }

    /** Whether any code path reads this key, per the catalog it defines them. */
    private static function inCatalog(string $key): bool
    {
        return in_array($key, array_column(SettingCatalog::defaults(), 'key'), true);
    }
}
