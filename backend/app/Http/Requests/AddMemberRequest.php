<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required_without:user_id', 'nullable', 'email'],
            'user_id' => ['required_without:email', 'nullable', 'exists:users,id'],
            'role' => ['required', 'string', 'in:owner,admin,operator,finance,auditor'],
        ];
    }
}
