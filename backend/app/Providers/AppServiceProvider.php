<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use App\Services\SystemSettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->defineRequestCeilings();
        Schema::defaultStringLength(191);

    }

    /**
     * Bound every `/api/v1` call, not just the four public auth routes.
     *
     * Until now `throttle` sat on `register`, `login`, `forgot-password` and
     * `reset-password` and nowhere else, so one signed-in account with a loop
     * could take the database down and the platform's own audit log would
     * record it as ordinary traffic. Three ceilings, because they answer three
     * different questions:
     *
     *   * **per account** — what one business's dashboard may legitimately do.
     *     A screen load costs a handful of calls (bootstrap, a list, its
     *     counts), so this is roomy for a person and small for a script.
     *   * **per address** — what a guest, who has no account to be good, may do.
     *   * **per organization** — the ceiling the three accounts of one company
     *     share, so a compromised staff login cannot spend the whole tenancy's
     *     budget through three separate user keys.
     *
     * The numbers come from `system_settings` (`security.*`) so an operator can
     * raise or lower them without a deployment; `config/hassal.php` holds the
     * fallback for a database that has never been installed. Missing rows
     * therefore throttle at a sane ceiling instead of failing open.
     *
     * Why the organization key is read from the header: this middleware runs
     * ahead of `tenant`, so `TenantContext` is still empty here, and the header
     * is what the tenant resolver will later validate. A forged header costs the
     * attacker their own request budget, and `ResolveOrganizationContext` still
     * refuses the request.
     */
    protected function defineRequestCeilings(): void
    {
        RateLimiter::for('api', function (Request $request) {
            /** @var SystemSettingsService $settings */
            $settings = $this->app->make(SystemSettingsService::class);

            $read = static function (string $key, int $fallback) use ($settings): int {
                // A zero, an empty row or a non-numeric edit must not turn the
                // ceiling off: clamp to at least one request per minute.
                $value = (int) $settings->get($key, $fallback);

                return $value > 0 ? $value : 1;
            };

            $limits = [];

            $user = $request->user();

            if ($user !== null) {
                $limits[] = Limit::perMinute(
                    $read('security.requests_per_minute', (int) config('hassal.rate_limit.per_account_per_minute'))
                )->by('hassal:account:' . $user->id);
            } else {
                $limits[] = Limit::perMinute(
                    $read('security.guest_requests_per_minute', (int) config('hassal.rate_limit.per_guest_per_minute'))
                )->by('hassal:guest:' . ($request->ip() ?? 'unknown'));
            }

            $organizationId = $request->header('X-Organization-Id');

            if (filled($organizationId)) {
                $limits[] = Limit::perMinute(
                    $read('security.organization_requests_per_minute', (int) config('hassal.rate_limit.per_organization_per_minute'))
                )->by('hassal:organization:' . $organizationId);
            }

            return $limits;
        });
    }
}
