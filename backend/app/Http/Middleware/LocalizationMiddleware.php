<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class LocalizationMiddleware
{
    /**
     * Supported application locales.
     */
    protected array $supportedLocales = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->header('Accept-Language');

        if ($locale) {
            // Extract the primary language code (e.g. "ar-SA,ar;q=0.9" => "ar")
            $primaryLang = strtolower(substr(trim(explode(',', $locale)[0]), 0, 2));

            if (in_array($primaryLang, $this->supportedLocales)) {
                App::setLocale($primaryLang);
            } else {
                App::setLocale(config('app.locale', 'ar'));
            }
        } else {
            App::setLocale(config('app.locale', 'ar'));
        }

        $response = $next($request);

        if (method_exists($response, 'header')) {
            $response->header('Content-Language', App::getLocale());
        }

        return $response;
    }
}
