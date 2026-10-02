<?php

namespace App\Rules;

use App\Support\OutboundEndpointPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Turns OutboundEndpointPolicy into a 422 the client can display.
 *
 * Registered on every field that becomes a URL this server will POST to, so a
 * tenant cannot get an internal address stored by using a different entry
 * point than the webhook form.
 */
class SafeWebhookEndpoint implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail('The webhook endpoint must be a URL string.');

            return;
        }

        foreach (OutboundEndpointPolicy::violations($value) as $violation) {
            $fail($violation);
        }
    }
}
