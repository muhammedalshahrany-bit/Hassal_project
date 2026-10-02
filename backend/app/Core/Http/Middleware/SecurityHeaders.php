<?php

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers every response should carry, set once rather than remembered at
 * each upload endpoint.
 *
 * `X-Content-Type-Options: nosniff` is the load-bearing one. Uploads here are
 * served back — a filed registration certificate opens through a signed link, a
 * product photograph loads from the public disk — and without nosniff a browser
 * is invited to second-guess the declared type and run something it decides
 * looks like HTML or script. The upload paths already derive the stored
 * extension from the bytes that were detected, never from the sender's chosen
 * name; nosniff is the matching guarantee on the way out, so the type the
 * server declared is the type the browser honours.
 *
 * `Referrer-Policy` keeps a signed document URL — signature token included —
 * from leaking to a third party through the Referer header when a reviewer
 * clicks through. `X-Frame-Options: DENY` stops any of this being framed into a
 * clickjacking page. All three are the boring, standard set; putting them here
 * means a newly written endpoint inherits them instead of having to remember.
 */
class SecurityHeaders
{
    /**
     * A policy for a document that should not exist.
     *
     * Every answer this middleware decorates is JSON on an `/api/v1` path, so
     * the strictest useful policy is the one that says *nothing* may be
     * fetched or executed. `'none'` rather than a list of allowed origins,
     * because there is no legitimate script, style, image or frame an API
     * response should be loading — and a policy written to allow something is a
     * policy that has to be re-read every time anyone wants to add a route.
     *
     * `frame-ancestors 'none'` is the modern half of `X-Frame-Options: DENY`;
     * both are sent because a browser that understands only the header is the
     * same browser that would otherwise clickjack a moderation queue.
     * `base-uri` and `form-action` close the two ways an injected fragment
     * escapes a CSP: rewriting the document's base URL, or submitting a form to
     * somewhere of the injector's choosing.
     *
     * `sandbox` is on the API responses because a filed document opened from a
     * signed URL is third-party content by definition. It costs nothing here —
     * these responses are read by an app and a gateway, not rendered as a page —
     * and it means an HTML body served from the storage host cannot run script
     * against the platform's own origin.
     */
    private const CONTENT_SECURITY_POLICY = "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; sandbox";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        $response->headers->set('X-DNS-Prefetch-Control', 'off');
        // Nothing on an API path is a page worth caching a copy of on a shared
        // machine, and `no-store` is the header that says so to a proxy as well
        // as to a browser. `X-Robots-Tag` is not a joke on an API: the public
        // marketplace listing routes are readable without a token, and a search
        // index is a second, unrevocable copy of every price a business filed.
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        // HSTS over plain HTTP is not a half-measure, it is a mistake: a browser
        // is specified to ignore the header when it arrives on an insecure
        // connection, so sending it there only advertises that the deployment
        // believes it has TLS when it does not. Local development runs over
        // http and must keep working, so the header is written when the request
        // really arrived over TLS — which behind the gateway depends on
        // `hassal.network.trusted_proxies` naming the gateway.
        if ($request->isSecure()) {
            // One year, all subdomains, and `preload` because the platform asks
            // its own operators to visit an admin console by name rather than by
            // a link typed into an http:// habit.
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        return $response;
    }
}
