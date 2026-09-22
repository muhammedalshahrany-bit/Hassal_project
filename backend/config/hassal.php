<?php

/*
|--------------------------------------------------------------------------
| HASSAL platform configuration
|--------------------------------------------------------------------------
|
| Environment-level wiring only. Anything the operator is expected to change
| after installation lives in the `system_settings` table and is read through
| App\Services\SystemSettingsService, because changing a value must not require
| a deploy or a code edit.
|
| This file deliberately contains NO country, currency, tax rate, commission
| rate or measurement default that the product would insist on. Those arrive
| from the database at runtime, seeded by the installer and editable by an
| administrator. env() is used here — and only here — never inside services,
| so `php artisan config:cache` cannot freeze a stale value.
|
*/

return [

    // Installed product surface. These are deployment choices, not feature
    // toggles the admin flips; the admin-facing flags live in feature_flags.
    'brand' => [
        'name' => env('HASSAL_BRAND_NAME', 'HASSAL'),
        // Declared by the release process, never by application code: a
        // client-visible "production" label has to be earned by the deploy.
        'app_version' => env('HASSAL_APP_VERSION'),
    ],

    // Where the web client lives. The backend hands this address to real
    // people — both browser-facing routes in routes/web.php redirect to it,
    // including the one a user lands on after clicking a password-reset
    // link — so it cannot be written into a route file: an address baked
    // into code sends every user who forgets a password to a developer's
    // laptop. Falls back to APP_URL for a single-host deployment.
    'frontend' => [
        'url' => rtrim((string) (env('HASSAL_FRONTEND_URL') ?: env('APP_URL', 'http://localhost:8000')), '/'),
    ],

    // Sanctum issues long-lived bearer tokens for the SPA and the mobile
    // clients. Expiry is a deployment decision: zero means "never", which is
    // wrong for a marketplace holding commercial data, so it defaults to 12
    // hours and every client must be able to re-authenticate.
    'auth' => [
        'token_ttl_minutes' => (int) env('HASSAL_TOKEN_TTL_MINUTES', 720),
        'remember_me_ttl_minutes' => (int) env('HASSAL_REMEMBER_ME_TTL_MINUTES', 20160),

        // The providers this deployment can hold an external login against.
        // It is a machine-level list, not an operator switch: adding a
        // provider means writing a verifier for it, and a settings screen that
        // offered `facebook` with no code behind it would be a switch that
        // lies. The `external_identities.provider` CHECK constraint is built
        // from these values, so the column, the verifier and the screens can
        // not drift into three different vocabularies.
        'external_identity_providers' => ['google'],

        // Which Google-issued tokens are accepted, and how long the signing
        // keys may be cached. The *client ID* — the value that says which
        // Google project this site belongs to — is deliberately NOT here: it
        // is `auth.google_client_id` in `system_settings`, because an operator
        // moving the product to their own Google Cloud project must not have to
        // edit a file and redeploy.
        'google' => [
            'jwks_url' => env('HASSAL_GOOGLE_JWKS_URL', 'https://www.googleapis.com/oauth2/v3/certs'),
            // Google has issued ID tokens under both spellings; both are
            // Google's own, and nothing else is accepted.
            'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
            'jwks_cache_seconds' => (int) env('HASSAL_GOOGLE_JWKS_CACHE_SECONDS', 21600),
            'http_timeout_seconds' => (int) env('HASSAL_GOOGLE_HTTP_TIMEOUT_SECONDS', 8),
            // A token whose expiry is inside this many seconds of the server's
            // clock is still honoured: two machines that disagree by half a
            // minute should not lock a real person out of their business. The
            // verifier clamps this to 300 seconds, because the setting tunes a
            // tolerance and is not a switch for the expiry check.
            'clock_leeway_seconds' => (int) env('HASSAL_GOOGLE_CLOCK_LEEWAY_SECONDS', 120),
            // And an issue date further in the future than this is a fault, not
            // a skew — the same rule read the other way.
            'max_future_skew_seconds' => (int) env('HASSAL_GOOGLE_MAX_FUTURE_SKEW_SECONDS', 300),
        ],
    ],

    // The states an account itself can be in, as opposed to a person's standing
    // inside a company (`rbac.membership_roles`) or on the platform
    // (`rbac.platform_roles`).
    //
    // They were previously written in three places with two capitalisations:
    // login compared against 'active', the status validator accepted
    // 'SUSPENDED'/'INACTIVE', and the column held whatever arrived. An account
    // set to 'SUSPENDED' was locked out while `auth/me` still described it as
    // ACTIVE, and 'ACTIVE' would have released a suspension nobody decided.
    // This list is now the only one, and the database enforces it.
    'accounts' => [
        'statuses' => ['active', 'suspended', 'inactive'],
    ],

    // Geographic discovery. HASSAL starts from one city; the radius ceiling
    // exists so a caller cannot ask the database to haversine the planet.
    'geo' => [
        'default_radius_km'   => (int) env('HASSAL_GEO_DEFAULT_RADIUS_KM', 100),
        'max_radius_km'       => (int) env('HASSAL_GEO_MAX_RADIUS_KM', 500),
        'earth_radius_km'     => 6371,
    ],

    // Uploads. A ceiling on bytes and a list of accepted types are safety
    // limits of this deployment (disk, bandwidth, what a reviewer can open),
    // so they are env-driven here and read by the controllers — no upload path
    // states a number of its own. They are deliberately NOT `system_settings`,
    // because an operator editing a limit through a web form must not be able
    // to raise it past what the server can hold.
    'uploads' => [
        'images' => [
            'max_bytes'       => (int) env('HASSAL_IMAGE_MAX_BYTES', 4 * 1024 * 1024),
            'max_per_product' => (int) env('HASSAL_IMAGE_MAX_PER_PRODUCT', 8),
            'accepted_mimes'  => ['image/jpeg', 'image/png', 'image/webp'],
        ],
        'documents' => [
            'max_bytes'      => (int) env('HASSAL_DOCUMENT_MAX_BYTES', 8 * 1024 * 1024),
            'max_per_org'    => (int) env('HASSAL_DOCUMENT_MAX_PER_ORG', 20),
            'accepted_mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
        ],
    ],

    // Request ceilings. The editable numbers are `security.*` in
    // `system_settings`; these are what the limiter falls back to on a database
    // that has never been installed, so a missing row throttles rather than
    // failing open. They are not a second source of truth an operator edits.
    'rate_limit' => [
        'per_account_per_minute' => (int) env('HASSAL_RATE_ACCOUNT_PER_MINUTE', 300),
        'per_guest_per_minute'   => (int) env('HASSAL_RATE_GUEST_PER_MINUTE', 60),
        'per_organization_per_minute' => (int) env('HASSAL_RATE_ORG_PER_MINUTE', 1500),
    ],

    // Reservation safety margins. The actual hold duration is a system setting
    // (reservation.timeout); this is the sweep interval and the grace period
    // that keeps a reservation from being released while its owner is paying.
    'reservations' => [
        // Fallback hold duration when the `reservation.timeout` setting row is
        // absent. The setting, not this number, is what an admin edits.
        'default_timeout_minutes' => (int) env('HASSAL_RESERVATION_TIMEOUT_MINUTES', 30),
        'expiry_grace_seconds' => (int) env('HASSAL_RESERVATION_GRACE_SECONDS', 60),
    ],

    // Integration providers are adapters behind a contract. No provider is
    // enabled by merely existing here: an adapter must be configured AND its
    // feature flag turned on, and an unconfigured adapter must make the
    // platform say "not available" rather than fake a result.
    'integrations' => [
        'payment' => env('HASSAL_PAYMENT_PROVIDER', 'manual'),
        'currency_rates' => env('HASSAL_RATE_PROVIDER', 'manual'),
        'verification' => env('HASSAL_VERIFICATION_PROVIDER', 'manual'),
        'maps' => env('HASSAL_MAPS_PROVIDER', 'openstreetmap'),
    ],

    // Webhook delivery. Signatures are timestamped to make replay bounded.
    'webhooks' => [
        // The endpoint is a URL a business supplies and this server then posts
        // to from inside its own network. Without this gate, "register a
        // webhook" is a request-forgery primitive against internal services
        // and cloud metadata endpoints. Only a development machine should
        // switch this on, and never a public deployment.
        'allow_private_endpoints' => (bool) env('HASSAL_WEBHOOK_ALLOW_PRIVATE_ENDPOINTS', false),
        'timeout_seconds' => (int) env('HASSAL_WEBHOOK_TIMEOUT_SECONDS', 10),
        'max_attempts'    => (int) env('HASSAL_WEBHOOK_MAX_ATTEMPTS', 5),
    ],
];
