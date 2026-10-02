<?php

namespace App\Auth\Requests;

use App\Auth\Requests\Concerns\CarriesDevice;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "This device, with these facts about it" — from the device itself.
 *
 * The route exists because the phone learns something about itself after the
 * sign-in: the person enables a fingerprint unlock a week later, or renames the
 * handset. Those are facts about a session the server already issued, so the
 * device reports them rather than the account being frozen at login time.
 *
 * A key that does not exist for this account is created. That is not an open
 * door: the row is written under `user_id` taken from the bearer token, so the
 * worst a fabricated key achieves is a device listing under the account that
 * made the call — which the same person can delete.
 */
class DeviceSyncRequest extends FormRequest
{
    use CarriesDevice;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'device_key' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ], $this->deviceRules());
    }
}
