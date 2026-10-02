<?php

namespace App\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An edit to one of the account's own devices.
 *
 * Only two things here are the device's to decide, and both are labels: what
 * the person calls the handset, and whether it says it opens with a
 * fingerprint. Neither changes what the token can do.
 */
class DeviceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'biometric_unlock' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
