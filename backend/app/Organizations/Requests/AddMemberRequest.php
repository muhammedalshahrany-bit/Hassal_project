<?php

namespace App\Organizations\Requests;

use App\Organizations\Models\OrganizationMembership;
use Illuminate\Foundation\Http\FormRequest;

class AddMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The role list comes from the model, so this request cannot drift away from
     * what the screens offer, and from the company's own list, so it cannot drift
     * away from what that company has decided a standing should be called.
     *
     * The vocabulary used to be a `CHECK` on `organization_memberships.role` and an
     * `in:` rule built from a global config list. Once a business may name a
     * standing of its own, neither can express it: a CHECK cannot reference another
     * table, and an `in:` list of the platform's five names would reject the sixth
     * one this company invented. Both now ask one question — is this key a standing
     * *this* organization has, or one of the platform's defaults the company may
     * not have been provisioned into yet — and the question needs the organization
     * id, which only the request that carries it can supply.
     *
     * `reason` is required because adding a person to a company is a grant of
     * access to that company's offers, deals and documents. Without a stated
     * reason in the audit trail, "who let them in and why" has no answer after
     * the two people involved stop agreeing.
     */
    public function rules(): array
    {
        return [
            'email' => ['required_without:user_id', 'nullable', 'email'],
            'user_id' => ['required_without:email', 'nullable', 'exists:users,id'],
            'role' => [
                'required',
                'string',
                'max:64',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $organizationId = $this->route('id');

                    if (!is_string($organizationId) && !is_int($organizationId)) {
                        $fail('المنشأة غير محددة في هذا الطلب');

                        return;
                    }

                    if (!OrganizationMembership::isStaffableRole((int) $organizationId, is_string($value) ? $value : null)) {
                        $fail('هذا الدور غير موجود في هذه المنشأة');
                    }
                },
            ],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
