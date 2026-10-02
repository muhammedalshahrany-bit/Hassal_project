<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Currency;
use App\Models\SystemSetting;
use App\Services\SystemSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The first-run commercial configuration screen.
 *
 * Every key this writes is a key in App\Support\SettingCatalog, and every one
 * of them is read by code. That is not a style rule: this controller used to
 * write `marketplace.require_offer_moderation` while the offer lifecycle read
 * `offer.requires_review`, so an operator who turned moderation on in the
 * wizard got a platform that never moderated anything and no error saying so.
 * A setting that no reader consumes is worse than no setting at all, because it
 * reports a control that does not exist.
 */
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
            'identity' => [
                'name_ar' => $this->settingsService->get('platform.name_ar'),
                'name_en' => $this->settingsService->get('platform.name_en'),
                'default_locale' => $this->settingsService->get('platform.default_locale'),
                'country' => $this->settingsService->get('platform.country'),
                'default_city' => $this->settingsService->get('platform.default_city'),
                'timezone' => $this->settingsService->get('platform.timezone'),
            ],
            'base_currency' => $this->settingsService->get('platform.default_currency'),
            'tax' => [
                'enabled' => (bool) $this->settingsService->get('tax.enabled', false),
                'name' => $this->settingsService->get('tax.name'),
                'rate' => (float) $this->settingsService->get('tax.rate', 0.0000),
                'inclusive' => (bool) $this->settingsService->get('tax.inclusive', false),
            ],
            // Zero, not 2.5%: an unconfigured platform charges nothing.
            'commission_rate' => (float) $this->settingsService->get('commission.rate', 0.0000),
            'operations' => [
                'reservation_timeout_minutes' => (int) $this->settingsService->get('reservation.timeout', 30),
                'inspection_window_hours'     => (int) $this->settingsService->get('inspection.window', 48),
                'offer_requires_review'       => (bool) $this->settingsService->get('offer.requires_review', false),
            ],
            'legal_requirements' => [
                'require_commercial_registration' => (bool) $this->settingsService->get('legal.require_commercial_registration', false),
                'require_vat_number'              => (bool) $this->settingsService->get('legal.require_vat_number', false),
            ],
        ], 'Setup wizard status retrieved');
    }

    public function configure(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform_name_ar'   => ['required', 'string', 'max:150'],
            'platform_name_en'   => ['required', 'string', 'max:150'],
            'default_locale'     => ['required', Rule::in(['ar', 'en'])],
            'country_code'       => ['sometimes', 'string', 'size:2'],
            'default_city'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'timezone'           => ['sometimes', 'string', 'max:64'],
            'default_currency'   => ['required', 'string', 'size:3'],
            'tax_enabled'        => ['required', 'boolean'],
            'tax_name'           => ['required', 'string', 'max:100'],
            'tax_rate'           => ['required', 'numeric', 'min:0', 'max:1'],
            'tax_inclusive'      => ['sometimes', 'boolean'],
            'commission_rate'    => ['required', 'numeric', 'min:0', 'max:0.5'],
            'reservation_timeout_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'inspection_window_hours'     => ['required', 'integer', 'min:12', 'max:168'],
            'require_offer_moderation'    => ['sometimes', 'boolean'],
            'require_commercial_registration' => ['sometimes', 'boolean'],
            'require_vat_number'          => ['sometimes', 'boolean'],
        ]);

        // The base currency has to be one the platform actually has. Choosing
        // "SAR" here while only YER exists would make every formatted price a
        // code with no rate, no symbol, and no decimals behind it.
        $currencyCode = strtoupper($validated['default_currency']);
        $currency = Currency::query()->where('iso_code', $currencyCode)->first();

        if (!$currency) {
            return ApiResponse::error(
                'العملة الأساسية يجب أن تُضاف أولاً في شاشة العملات',
                'CURRENCY_NOT_DEFINED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['default_currency' => 'لم يتم العثور على هذه العملة في النظام']
            );
        }

        if (!$currency->is_active) {
            return ApiResponse::error(
                'العملة الأساسية يجب أن تكون مفعلة',
                'CURRENCY_NOT_ACTIVE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Identity — the group argument matches the catalog so the settings
        // screen groups what the operator edits.
        $this->settingsService->set('platform.name_ar', $validated['platform_name_ar'], 'string', 'platform');
        $this->settingsService->set('platform.name_en', $validated['platform_name_en'], 'string', 'platform');
        $this->settingsService->set('platform.default_locale', $validated['default_locale'], 'string', 'platform');
        $this->settingsService->set('platform.default_currency', $currencyCode, 'string', 'platform');

        if (array_key_exists('country_code', $validated)) {
            $this->settingsService->set('platform.country', strtoupper($validated['country_code']), 'string', 'platform');
        }
        if (array_key_exists('default_city', $validated)) {
            $this->settingsService->set('platform.default_city', $validated['default_city'], 'string', 'platform');
        }
        if (array_key_exists('timezone', $validated)) {
            $this->settingsService->set('platform.timezone', $validated['timezone'], 'string', 'platform');
        }

        // Money
        $this->settingsService->set('tax.enabled', $validated['tax_enabled'] ? 'true' : 'false', 'boolean', 'tax');
        $this->settingsService->set('tax.name', $validated['tax_name'], 'string', 'tax');
        $this->settingsService->set('tax.rate', $validated['tax_rate'], 'decimal', 'tax');
        if (array_key_exists('tax_inclusive', $validated)) {
            $this->settingsService->set('tax.inclusive', $validated['tax_inclusive'] ? 'true' : 'false', 'boolean', 'tax');
        }
        $this->settingsService->set('commission.rate', $validated['commission_rate'], 'decimal', 'commission');

        // Operations
        $this->settingsService->set('reservation.timeout', $validated['reservation_timeout_minutes'], 'integer', 'operations');
        $this->settingsService->set('inspection.window', $validated['inspection_window_hours'], 'integer', 'operations');
        if (array_key_exists('require_offer_moderation', $validated)) {
            $this->settingsService->set('offer.requires_review', $validated['require_offer_moderation'] ? 'true' : 'false', 'boolean', 'operations');
        }

        // Which legal identifiers a business must supply. These gate the
        // registration form and the platform's verification decision; neither
        // claims that anything was checked against an official register.
        if (array_key_exists('require_commercial_registration', $validated)) {
            $this->settingsService->set(
                'legal.require_commercial_registration',
                $validated['require_commercial_registration'] ? 'true' : 'false',
                'boolean',
                'legal'
            );
        }
        if (array_key_exists('require_vat_number', $validated)) {
            $this->settingsService->set('legal.require_vat_number', $validated['require_vat_number'] ? 'true' : 'false', 'boolean', 'legal');
        }

        // Keep the two homes for "which currency is the base" in agreement: the
        // flag on the currency row drives rate math, this setting drives every
        // formatted price.
        if (!$currency->is_default) {
            Currency::query()->where('is_default', true)->update(['is_default' => false]);
            $currency->update([
                'is_default' => true,
                'exchange_rate_to_base' => 1.000000,
                'rate_source' => 'BASE_CURRENCY',
                'rate_updated_at' => now(),
                'rate_updated_by_user_id' => $request->user()->id,
            ]);
        }

        $this->settingsService->set('platform.installed', 'true', 'boolean', 'platform');

        return ApiResponse::success($this->status()->getData(true)['data'] ?? null, 'تم حفظ إعدادات المنصة');
    }
}
