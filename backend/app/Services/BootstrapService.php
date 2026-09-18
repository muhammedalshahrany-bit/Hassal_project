<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Offer;
use App\Models\User;

class BootstrapService
{
    public function __construct(
        protected SystemSettingsService $settingsService,
        protected FeatureFlagService $featureFlagService
    ) {}

    /**
     * Generates a unified, high-speed bootstrap payload for Flutter Mobile & Web Client initialization.
     */
    public function getBootstrapPayload(?User $user = null, ?int $organizationId = null): array
    {
        $currencies = Currency::where('is_active', true)->get();
        $categories = Category::where('is_active', true)->whereNull('parent_id')->with('children')->orderBy('sort_order')->get();
        $featureFlags = $this->featureFlagService->getAllFlags();
        $publicSettings = $this->settingsService->getPublicSettings();

        $opportunityReasons = [
            ['code' => Offer::REASON_SURPLUS_STOCK, 'label_ar' => 'مخزون راكد وفائض', 'label_en' => 'Surplus Stock'],
            ['code' => Offer::REASON_SLOW_MOVING, 'label_ar' => 'بضاعة بطيئة الحركة', 'label_en' => 'Slow Moving'],
            ['code' => Offer::REASON_SEASONAL_STOCK, 'label_ar' => 'مخزون موسمي وفائض مواسم', 'label_en' => 'Seasonal Stock'],
            ['code' => Offer::REASON_EXCESS_INVENTORY, 'label_ar' => 'كميات فائضة عن خطة البيع', 'label_en' => 'Excess Inventory'],
            ['code' => Offer::REASON_LIQUIDATION, 'label_ar' => 'تصفية شاملة وعاجلة', 'label_en' => 'Liquidation'],
            ['code' => Offer::REASON_BRANCH_CLOSURE, 'label_ar' => 'إغلاق فروع ومستودعات', 'label_en' => 'Branch Closure'],
            ['code' => Offer::REASON_ASSORTMENT_CHANGE, 'label_ar' => 'تغيير التشكيلة والمنتجات', 'label_en' => 'Assortment Change'],
            ['code' => Offer::REASON_LOW_LOCAL_DEMAND, 'label_ar' => 'ضعف الطلب في النطاق الجغرافي', 'label_en' => 'Low Local Demand'],
        ];

        $payload = [
            'app_version' => '2.0.0-production',
            'api_version' => 'v1',
            'system_status' => 'OPERATIONAL',
            'server_time' => now()->toIso8601String(),
            'default_locale' => $this->settingsService->get('platform.default_locale', 'ar'),
            'supported_locales' => ['ar', 'en'],
            'active_locale' => app()->getLocale(),
            'country' => $this->settingsService->get('platform.country', env('PLATFORM_COUNTRY', 'YE')),
            'base_currency' => $this->settingsService->get('platform.default_currency', 'YER'),
            'currencies' => $currencies,
            'categories' => $categories,
            'opportunity_reasons' => $opportunityReasons,
            'feature_flags' => $featureFlags,
            'platform_settings' => [
                'tax' => [
                    'enabled' => (bool) $this->settingsService->get('tax.enabled', false),
                    'name' => $this->settingsService->get('tax.name', 'ضريبة المبيعات'),
                    'rate' => (float) $this->settingsService->get('tax.rate', 0.0000),
                    'inclusive' => (bool) $this->settingsService->get('tax.inclusive', false),
                ],
                'commission_rate' => (float)$this->settingsService->get('commission.rate', 0.0250),
                'reservation_timeout_minutes' => (int)$this->settingsService->get('reservation.timeout', 30),
                'inspection_window_hours' => (int)$this->settingsService->get('inspection.window', 48),
                'public_configs' => $publicSettings,
            ],
            'user' => null,
            'tenant' => null,
        ];

        if ($user) {
            $user->load(['memberships.organization', 'roles']);
            $currentOrg = $organizationId
                ? $user->organizations()->where('organizations.id', $organizationId)->first()
                : $user->organizations()->first();

            $payload['user'] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ];

            if ($currentOrg) {
                $payload['tenant'] = [
                    'id' => $currentOrg->id,
                    'name_ar' => $currentOrg->name_ar,
                    'name_en' => $currentOrg->name_en,
                    'type' => $currentOrg->type,
                    'status' => $currentOrg->status,
                    'commercial_registration_number' => $currentOrg->commercial_registration_number,
                    'vat_number' => $currentOrg->vat_number,
                    'rating' => $currentOrg->rating,
                    'role_in_organization' => $user->roleInOrganization($currentOrg->id),
                ];
            }
        }

        return $payload;
    }
}
