<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The credential Google's button handed the browser.
 *
 * `credential` is the ID token, and `nonce` is the random value this browser
 * generated before asking Google for it. Both are required: a client that can
 * present a token without the nonce it was asked for is presenting a token it
 * carried in from somewhere else, and the whole point of the nonce is that the
 * server can tell the two apart.
 */
class GoogleSignInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // A JWT is three base64url segments; 4096 characters is far above
            // any real Google token and well below anything worth parsing.
            'credential' => ['required', 'string', 'max:4096'],
            'nonce' => ['required', 'string', 'min:16', 'max:128'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
