<?php

namespace App\Auth\Requests\Concerns;

use App\Auth\Models\UserDevice;

/**
 * The optional device fields a sign-in may carry, defined once.
 *
 * Login, registration and provider sign-in all hand the client a session, and
 * all three therefore have to be able to say which device that session lives
 * on. Three copies of the rules is how one of them starts accepting a platform
 * the others reject, so the vocabulary is this trait's to own.
 *
 * `device_key` is not a secret and not a credential: it is a client-generated
 * label the device row is found by inside one account. Nothing in this project
 * authorises anything with it — every device route sits behind `auth:sanctum`,
 * and the biometric flag it can set is a warning label for the account screen,
 * never a way in.
 */
trait CarriesDevice
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function deviceRules(): array
    {
        return [
            'device_name' => ['nullable', 'string', 'max:100'],
            'device_key' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'device_platform' => ['nullable', 'string', 'in:android,ios,web'],
            'biometric_unlock' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The device this request came from, or null when it said nothing.
     *
     * A caller that sends no key is a normal session that simply will not
     * appear in the account's device list — the behaviour that existed before
     * this feature, kept intact for anything that has not adopted it.
     *
     * @return array{key: string, name: string, platform: string, biometric_unlock: bool}|null
     */
    public function device(): ?array
    {
        $key = $this->input('device_key');
        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        $platform = is_string($this->input('device_platform')) ? strtolower(trim($this->input('device_platform'))) : 'web';
        if (! in_array($platform, UserDevice::PLATFORMS, true)) {
            $platform = 'web';
        }

        $name = is_string($this->input('device_name')) ? trim($this->input('device_name')) : '';

        return [
            'key' => substr(trim($key), 0, 120),
            // The token's name and the device row's label are one string on
            // purpose: two names for one session is how a revoked device and a
            // living token end up in the same account.
            'name' => $name !== '' ? substr($name, 0, 120) : ucfirst($platform),
            'platform' => $platform,
            'biometric_unlock' => $this->boolean('biometric_unlock'),
        ];
    }
}
