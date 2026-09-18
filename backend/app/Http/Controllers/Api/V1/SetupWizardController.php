<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Currency;
use App\Models\SystemSetting;
use App\Services\SystemSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SetupWizardController extends Controller
{
    public function __construct(
        protected SystemSettingsService $settingsService
    ) {}

    public function status(): JsonResponse
    {
        $isInstalled = SystemSetting::where('key', 'platform.installed')->value('value') === 'true';

        return ApiResponse::success([
            'is_installed' => $isInstalled,
            'platform_name' => $this->settingsService->get('platform.name_ar', 'منصة حصّال'),
            'base_currency' => $this->settingsService->get('platform.default_currency', 'YER'),
            'tax' => [
                'enabled' => $this->settingsService->get('tax.enabled', false),
                'name' => $this->settingsService->get('tax.name', 'ضريبة المبيعات'),
                'rate' => $this->settingsService->get('tax.rate', 0.0000),
            ],
            'commission_rate' => $this->settingsService->get('commission.rate', 0.0250),
        ], 'Setup wizard status retrieved');
    }

    public function configure(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform_name_ar' => 'required|string|max:150',
            'platform_name_en' => 'required|string|max:150',
            'default_locale' => 'required|in:ar,en,fr,tr,ur',
            'default_currency' => 'required|string|size:3',
            'tax_enabled' => 'required|boolean',
            'tax_name' => 'required|string|max:100',
            'tax_rate' => 'required|numeric|min:0|max:1',
            'commission_rate' => 'required|numeric|min:0|max:0.5',
            'reservation_timeout_minutes' => 'required|integer|min:5|max:1440',
            'inspection_window_hours' => 'required|integer|min:12|max:168',
            'require_offer_moderation' => 'boolean',
            'enforce_kyb_verification' => 'boolean',
        ]);

        $this->settingsService->set('platform.name_ar', $validated['platform_name_ar'], 'string', 'platform');
        $this->settingsService->set('platform.name_en', $validated['platform_name_en'], 'string', 'platform');
        $this->settingsService->set('platform.default_locale', $validated['default_locale'], 'string', 'platform');
        $this->settingsService->set('platform.default_currency', $validated['default_currency'], 'string', 'platform');
        $this->settingsService->set('tax.enabled', $validated['tax_enabled'] ? 'true' : 'false', 'boolean', 'tax');
        $this->settingsService->set('tax.name', $validated['tax_name'], 'string', 'tax');
        $this->settingsService->set('tax.rate', $validated['tax_rate'], 'decimal', 'tax');
        $this->settingsService->set('commission.rate', $validated['commission_rate'], 'decimal', 'commission');
        $this->settingsService->set('reservation.timeout', $validated['reservation_timeout_minutes'], 'integer', 'reservation');
        $this->settingsService->set('inspection.window', $validated['inspection_window_hours'], 'integer', 'inspection');
        
        if (isset($validated['require_offer_moderation'])) {
            $this->settingsService->set('marketplace.require_offer_moderation', $validated['require_offer_moderation'] ? 'true' : 'false', 'boolean', 'marketplace');
        }
        if (isset($validated['enforce_kyb_verification'])) {
            $this->settingsService->set('compliance.enforce_kyb_verification', $validated['enforce_kyb_verification'] ? 'true' : 'false', 'boolean', 'compliance');
        }

        $this->settingsService->set('platform.installed', 'true', 'boolean', 'platform');

        return ApiResponse::success(null, 'Platform commercial configuration saved successfully');
    }
}
