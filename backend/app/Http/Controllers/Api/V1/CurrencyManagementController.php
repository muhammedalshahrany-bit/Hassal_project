<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Currency;
use App\Services\SystemSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencyManagementController extends Controller
{
    public function __construct(
        protected SystemSettingsService $settingsService
    ) {}

    public function index(): JsonResponse
    {
        $currencies = Currency::orderBy('is_default', 'desc')->orderBy('iso_code', 'asc')->get();
        return ApiResponse::success($currencies, 'Currencies retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'iso_code' => 'required|string|size:3|unique:currencies,iso_code',
            'name_ar' => 'required|string|max:100',
            'name_en' => 'required|string|max:100',
            'symbol' => 'required|string|max:10',
            'decimal_places' => 'required|integer|min:0|max:6',
            'exchange_rate_to_base' => 'required|numeric|min:0.000001',
            'is_active' => 'boolean',
        ]);

        $currency = Currency::create($validated + [
            'rate_source' => 'MANUAL_ADMIN_RATE',
            'rate_updated_at' => now(),
            'rate_updated_by_user_id' => $request->user()->id,
        ]);
        return ApiResponse::success($currency, 'Currency created successfully', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $currency = Currency::findOrFail($id);

        $validated = $request->validate([
            'name_ar' => 'string|max:100',
            'name_en' => 'string|max:100',
            'symbol' => 'string|max:10',
            'decimal_places' => 'integer|min:0|max:6',
            'exchange_rate_to_base' => 'numeric|min:0.000001',
            'is_active' => 'boolean',
        ]);

        if (array_key_exists('exchange_rate_to_base', $validated)) {
            $validated['rate_source'] = 'MANUAL_ADMIN_RATE';
            $validated['rate_updated_at'] = now();
            $validated['rate_updated_by_user_id'] = $request->user()->id;
        }
        $currency->update($validated);
        return ApiResponse::success($currency, 'Currency updated successfully');
    }

    public function setDefault(int $id): JsonResponse
    {
        $currency = Currency::findOrFail($id);

        // Reset previous defaults
        Currency::where('is_default', true)->update(['is_default' => false]);

        $currency->update([
            'is_default' => true,
            'is_active' => true,
            'exchange_rate_to_base' => 1.000000,
            'rate_source' => 'BASE_CURRENCY',
            'rate_updated_at' => now(),
            'rate_updated_by_user_id' => request()->user()->id,
        ]);

        // The setting is what every price is labelled with; the flag above is
        // what rates are measured against. One action, both updated, or the
        // platform quotes in a currency it does not convert from.
        $this->settingsService->set('platform.default_currency', $currency->iso_code, 'string', 'platform');

        return ApiResponse::success($currency, 'Default base currency updated successfully');
    }
}
