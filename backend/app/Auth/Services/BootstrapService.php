<?php

namespace App\Auth\Services;

use App\Platform\Services\FeatureFlagService;
use App\Platform\Services\SystemSettingsService;

use App\Catalog\Models\Category;
use App\Core\Tenant\TenantContext;
use App\Platform\Models\Currency;
use App\Marketplace\Models\Offer;
use App\Identity\Models\User;
use Illuminate\Support\Facades\DB;

class BootstrapService
{
    /**
     * How each opportunity reason is worded for a person filling the form.
     *
     * The codes themselves are `Offer::OPPORTUNITY_REASONS`' — the same list
     * every validator accepts a listing under — so a reason can never be
     * filable but missing from the dropdown, or offered with no door that
     * would take it. Only the wording lives here.
     */
    private const OPPORTUNITY_REASON_LABELS = [
        Offer::REASON_SURPLUS_STOCK     => ['ar' => 'مخزون راكد وفائض', 'en' => 'Surplus Stock'],
        Offer::REASON_SLOW_MOVING       => ['ar' => 'بضاعة بطيئة الحركة', 'en' => 'Slow Moving'],
        Offer::REASON_SEASONAL_STOCK    => ['ar' => 'مخزون موسمي وفائض مواسم', 'en' => 'Seasonal Stock'],
        Offer::REASON_EXCESS_INVENTORY  => ['ar' => 'كميات فائضة عن خطة البيع', 'en' => 'Excess Inventory'],
        Offer::REASON_LIQUIDATION       => ['ar' => 'تصفية شاملة وعاجلة', 'en' => 'Liquidation'],
        Offer::REASON_BRANCH_CLOSURE    => ['ar' => 'إغلاق فروع ومستودعات', 'en' => 'Branch Closure'],
        Offer::REASON_ASSORTMENT_CHANGE => ['ar' => 'تغيير التشكيلة والمنتجات', 'en' => 'Assortment Change'],
        Offer::REASON_LOW_LOCAL_DEMAND  => ['ar' => 'ضعف الطلب في النطاق الجغرافي', 'en' => 'Low Local Demand'],
    ];

    public function __construct(
        protected SystemSettingsService $settingsService,
        protected FeatureFlagService $featureFlagService,
        protected SessionService $sessions
    ) {}

    /**
     * Reference data the client needs before it can draw a screen: locales,
     * currencies, categories, opportunity reasons, feature flags and the
     * commercial settings. Consumed by the web gateway, and by any client that
     * speaks /api/v1.
     *
     * Which business the answer speaks for is read from the resolved request
     * context rather than taken as an argument, because that context is what the
     * capabilities in the payload were already assembled from — see the note on
     * `$currentOrg` below.
     */
    public function getBootstrapPayload(?User $user = null): array
    {
        $currencies = Currency::where('is_active', true)->get();
        $categories = Category::where('is_active', true)->whereNull('parent_id')->with('children')->orderBy('sort_order')->get();
        $featureFlags = $this->featureFlagService->getAllFlags();
        $publicSettings = $this->settingsService->getPublicSettings();

        $opportunityReasons = array_map(
            fn (string $code): array => [
                'code' => $code,
                'label_ar' => self::OPPORTUNITY_REASON_LABELS[$code]['ar'] ?? $code,
                'label_en' => self::OPPORTUNITY_REASON_LABELS[$code]['en'] ?? $code,
            ],
            Offer::OPPORTUNITY_REASONS
        );

        $payload = [
            'app_name' => config('app.name'),
            'app_version' => config('hassal.brand.app_version'),
            'api_version' => 'v1',
            'system_status' => $this->databaseIsReachable() ? 'OPERATIONAL' : 'DEGRADED',
            'server_time' => now()->toIso8601String(),
            'default_locale' => $this->settingsService->get('platform.default_locale'),
            'supported_locales' => config('app.supported_locales') ?: ['ar', 'en'],
            'active_locale' => app()->getLocale(),
            // null until an operator configures it: the client must be able to
            // tell "not configured" apart from a guessed value.
            'country' => $this->settingsService->get('platform.country'),
            'base_currency' => $this->settingsService->get('platform.default_currency'),
            'currencies' => $currencies,
            'categories' => $categories,
            'opportunity_reasons' => $opportunityReasons,
            'feature_flags' => $featureFlags,
            'platform_settings' => [
                'tax' => [
                    'enabled' => (bool) $this->settingsService->get('tax.enabled', false),
                    'name' => $this->settingsService->get('tax.name'),
                    'rate' => (float) $this->settingsService->get('tax.rate', 0.0000),
                    'inclusive' => (bool) $this->settingsService->get('tax.inclusive', false),
                ],
                'commission_rate' => (float) $this->settingsService->get('commission.rate', 0.0000),
                'reservation_timeout_minutes' => (int) $this->settingsService->get('reservation.timeout', config('hassal.reservations.default_timeout_minutes')),
                'inspection_window_hours' => (int) $this->settingsService->get('inspection.window', 48),
                // The city this deployment starts from. A client needs it for
                // search and form defaults; it must never carry one of its own.
                'default_city' => $this->settingsService->get('platform.default_city'),
                'public_configs' => $publicSettings,
            ],
            'user' => null,
            'tenant' => null,
        ];

        if ($user) {
            // `roles` is loaded because the account block names them; the
            // memberships relation is not, because the business in context is no
            // longer chosen from it (see below).
            $user->load('roles');

            // The same account block a sign-in and `/auth/me` answer with — one
            // person, one shape, so a client that reads bootstrap first and the
            // profile second cannot find a field missing.
            $payload['user'] = $this->sessions->account($user);

            // The business this request is acting as, as the `tenant` middleware
            // resolved it — the one holder `/auth/me` reads. It used to be picked
            // here by asking which organization this person belongs to, which
            // could name a different company than the one whose standing the
            // `permissions` just above were computed from: one payload describing
            // two businesses. A platform role acting on a company it is not a
            // member of now appears the way `/auth/me` already shows it, with a
            // null standing rather than no business at all.
            $currentOrg = TenantContext::get();

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
                    'role_in_organization' => TenantContext::role(),
                ];
            }
        }

        return $payload;
    }

    /**
     * Whether the database answered just now — the same measurement `/health`
     * reports. A client must be able to tell "the platform is down" apart from
     * "nobody looked"; a compiled-in OPERATIONAL cannot make that distinction.
     */
    private function databaseIsReachable(): bool
    {
        try {
            return DB::connection()->getPdo() !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
