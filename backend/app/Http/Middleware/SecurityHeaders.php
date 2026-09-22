<?php

namespace App\Http\Middleware;

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
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
