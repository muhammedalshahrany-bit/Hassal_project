<?php

namespace App\Organizations\Requests;

use App\Organizations\Models\Organization;
use App\Platform\Services\SystemSettingsService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for registering a business.
 *
 * Two things used to be hardcoded here and neither is the code's to decide:
 *
 *   1. Which legal identifiers a business must supply. That follows the law of
 *      the country the platform operates in, so it is an administrator setting
 *      (`legal.*`) and this request reads it. Absent a number is not absent a
 *      business — it is a business whose registration the platform has to
 *      handle in whatever way its rules allow.
 *   2. Who may create which kind of organization. `admin` is a platform role,
 *      not a business type a signed-up buyer can hand themselves; self-service
 *      is limited to buyer and seller, and only SuperAdmin may create the
 *      others.
 */
class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(SystemSettingsService $settings): array
    {
        $isPlatformAdmin = $this->user()?->isPlatformOperator() ?? false;

        $rules = [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ];

        $rules['type'] = $isPlatformAdmin
            ? ['required', 'in:' . implode(',', [
                Organization::TYPE_BUYER,
                Organization::TYPE_SELLER,
                Organization::TYPE_INTERMEDIARY,
                Organization::TYPE_ADMIN,
            ])]
            : ['required', 'in:' . Organization::TYPE_BUYER . ',' . Organization::TYPE_SELLER];

        $rules['commercial_registration_number'] = $this->legalIdentifierRules(
            (bool) $settings->get('legal.require_commercial_registration', false)
        );

        $rules['vat_number'] = $this->legalIdentifierRules(
            (bool) $settings->get('legal.require_vat_number', false)
        );

        return $rules;
    }

    /**
     * @return array<int, mixed>
     */
    protected function legalIdentifierRules(bool $required): array
    {
        // `nullable` short-circuits the rest of the list, so when a field is
        // optional and left blank it is stored as "not provided" — not as a
        // placeholder somebody later mistakes for a real registration.
        $rules = $required ? ['required', 'string', 'max:50'] : ['nullable', 'string', 'max:50'];

        return $rules;
    }

    /**
     * Uniqueness, expressed separately so an optional blank field never
     * collides with another blank field.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ([
                'commercial_registration_number' => 'commercial_registration_number',
                'vat_number' => 'vat_number',
            ] as $field => $column) {
                $value = $this->input($field);

                if (blank($value)) {
                    continue;
                }

                if (Organization::where($column, $value)->exists()) {
                    $validator->errors()->add($field, 'هذا المعرّف مسجَّل لمنشأة أخرى.');
                }
            }
        });
    }
}
