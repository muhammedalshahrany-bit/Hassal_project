<?php

/*
|--------------------------------------------------------------------------
| HASSAL platform configuration
|--------------------------------------------------------------------------
|
| Environment-level wiring only. Anything the operator is expected to change
| after installation lives in the `system_settings` table and is read through
| App\Platform\Services\SystemSettingsService, because changing a value must not require
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

    // The first platform operator, for an install that runs with no terminal in
    // front of it — a container entrypoint, a CI step. `app:install-system`
    // reads these before it prompts, and an option given on the command line
    // still wins over both.
    //
    // There is no default password here, and none anywhere in this repository:
    // an unset value falls back to the interactive prompt rather than to a
    // string an installer would quietly accept. What reaches the database is
    // the hash the `User` model makes on assignment, never the value itself, so
    // nothing in this block is recoverable from the row afterwards.
    'install' => [
        'admin_name' => env('HASSAL_ADMIN_NAME'),
        'admin_email' => env('HASSAL_ADMIN_EMAIL'),
        'admin_password' => env('HASSAL_ADMIN_PASSWORD'),
    ],

    // The demo world's shared sign-in, for `SandboxSeeder` only.
    //
    // No default, for the same reason the installer has none: a password the
    // repository knows is a password nobody chose. `SandboxSeeder` refuses to run
    // without it, so a developer states out loud that these accounts are open in
    // this environment — which is also why the seeder itself is refused in
    // production, and why a production install has no account whose password
    // appears in a file.
    'sandbox' => [
        'password' => env('HASSAL_SANDBOX_PASSWORD'),
    ],

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

    // Which addresses may rewrite what this application believes about the
    // request: the client IP, the protocol and the host.
    //
    // This is a correctness setting before it is a security one. Every request
    // reaches Laravel through the gateway, so with no trusted proxy
    // `throttle:10,1` on `POST /auth/login` counts the gateway's own address —
    // ten sign-ins a minute for the entire platform — `$request->isSecure()` is
    // false on a TLS deployment so HSTS is never sent, and every audit row names
    // the proxy as the actor.
    //
    // `*` is right for the architecture this ships in, where Laravel binds to
    // 127.0.0.1 and the gateway is the only public listener. A deployment that
    // exposes the app directly must name its load balancer instead: trusting any
    // address lets a client forge its own IP and walk round its own ban.
    'network' => [
        'trusted_proxies' => env('HASSAL_TRUSTED_PROXIES', '*'),
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

    // Deliberately absent: a block naming "which payment provider" or "which
    // verification provider" this installation calls. No code reads such a
    // switch, and a configuration key that nothing consumes is not a setting —
    // it is a promise the platform does not keep. HASSAL records trades; money
    // changes hands between the two businesses, outside it.

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
