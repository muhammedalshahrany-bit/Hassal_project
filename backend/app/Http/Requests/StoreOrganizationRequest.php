<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:buyer,seller,intermediary,admin'],
            'commercial_registration_number' => ['required', 'string', 'max:50', 'unique:organizations,commercial_registration_number'],
            'vat_number' => ['required', 'string', 'max:50', 'unique:organizations,vat_number'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
