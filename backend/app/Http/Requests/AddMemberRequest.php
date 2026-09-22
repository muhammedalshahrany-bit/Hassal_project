<?php

namespace App\Http\Requests;

use App\Models\OrganizationMembership;
use Illuminate\Foundation\Http\FormRequest;

class AddMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The role list comes from the model, so this request cannot drift away
     * from what the column stores or from what the screens offer.
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
            'role' => ['required', 'string', OrganizationMembership::roleRule()],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
