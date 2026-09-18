<?php

return [
    /*
    |--------------------------------------------------------------------------
    | HASSAL Marketplace Core System Configuration
    |--------------------------------------------------------------------------
    |
    | Global operational parameters for inventory reservations, tax rates,
    | commissions, supported currencies, and localization settings.
    |
    */

    'platform_name' => env('HASSAL_PLATFORM_NAME', 'حصّال — HASSAL B2B Food & Inventory Marketplace'),

    'default_currency' => env('HASSAL_DEFAULT_CURRENCY', 'SAR'),

    'supported_currencies' => ['SAR', 'YER', 'USD', 'AED'],

    'default_locale' => env('HASSAL_DEFAULT_LOCALE', 'ar'),

    'supported_locales' => ['ar', 'en'],

    'vat_rate' => (float) env('HASSAL_VAT_RATE', 0.15), // 15% ZATCA standard VAT

    'commission_rate' => (float) env('HASSAL_COMMISSION_RATE', 0.025), // 2.5% platform commission

    'reservation_timeout_minutes' => (int) env('HASSAL_RESERVATION_TIMEOUT_MINUTES', 30),

    'inspection_window_hours' => (int) env('HASSAL_INSPECTION_WINDOW_HOURS', 48),

    'default_geo_radius_km' => (int) env('HASSAL_DEFAULT_GEO_RADIUS_KM', 250),

    'feature_flags' => [
        'enable_rfq' => env('FEATURE_ENABLE_RFQ', true),
        'enable_escrow' => env('FEATURE_ENABLE_ESCROW', true),
        'enable_geo_discovery' => env('FEATURE_ENABLE_GEO_DISCOVERY', true),
        'enable_broker' => env('FEATURE_ENABLE_BROKER', true),
        'enable_online_payment' => env('FEATURE_ENABLE_ONLINE_PAYMENT', true),
        'enable_notifications' => env('FEATURE_ENABLE_NOTIFICATIONS', true),
        'enable_flutter_api' => env('FEATURE_ENABLE_FLUTTER_API', true),
    ],
];
