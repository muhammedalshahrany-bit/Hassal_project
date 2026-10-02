<?php

namespace App\Auth\Support;

use App\Identity\Models\User;
use App\Platform\Services\SystemSettingsService;

/**
 * What this platform still needs from an account before it may act.
 *
 * The list is computed in one place because two different doors have to agree
 * on it: `/auth/me` tells a person what is missing so a screen can ask, and
 * `POST /organizations` refuses until it arrives. When each door held its own
 * copy of "what is required", a form could be satisfied and the action still
 * refused, or — the usual failure the other way — the action passed while the
 * form kept nagging.
 *
 * The switches are settings, not constants, because which identity details a
 * marketplace may demand is a question about the law and the customs of the
 * country it operates in. A deployment that must not ask for a national number
 * turns the switch off, and the same code stops asking.
 *
 * Nothing here claims any of it was *verified against a register*. A phone
 * number typed into a form is a claim until the account holder proves reachability;
 * an ID typed into a form is a claim full stop. What completing them buys is a
 * counterparty who can make contact and a record a dispute can be read from —
 * not a government's approval this platform does not have.
 */
final class AccountRequirements
{
    public const FIELD_PHONE = 'phone';
    public const FIELD_NATIONAL_ID = 'national_id';

    public function __construct(private readonly SystemSettingsService $settings)
    {
    }

    /**
     * The fields this deployment demands, in the order a form should show them.
     *
     * @return list<string>
     */
    public function requiredFields(): array
    {
        $required = [];

        if ($this->settingIsOn('account.require_phone_number')) {
            $required[] = self::FIELD_PHONE;
        }

        if ($this->settingIsOn('account.require_national_id')) {
            $required[] = self::FIELD_NATIONAL_ID;
        }

        return $required;
    }

    /**
     * The demanded fields this account has not supplied.
     *
     * @return list<string>
     */
    public function missingFields(User $user): array
    {
        return array_values(array_filter(
            $this->requiredFields(),
            static fn (string $field): bool => trim((string) ($user->{$field} ?? '')) === ''
        ));
    }

    public function isComplete(User $user): bool
    {
        return $this->missingFields($user) === [];
    }

    /**
     * The switches read as strings as easily as booleans, because a
     * `system_settings` row typed `boolean` stores `'1'` and a
     * `filter_var(false)` on the string `'false'` says off while a plain
     * `(bool) 'false'` says on. This is the one place that decides.
     */
    private function settingIsOn(string $key): bool
    {
        $value = $this->settings->get($key, false);

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
        }

        return $value === true;
    }
}
