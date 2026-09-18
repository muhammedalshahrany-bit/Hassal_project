<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'national_id' => ['nullable', 'string', 'max:30'],
            'password' => ['sometimes', 'string', 'min:8'],
            'organization_name' => ['nullable', 'string', 'max:255'],
            'organization_type' => ['nullable', 'in:buyer,seller,intermediary'],
            'commercial_registration_number' => ['nullable', 'string', 'max:50'],
            'vat_number' => ['nullable', 'string', 'max:50'],
        ];
    }
}
