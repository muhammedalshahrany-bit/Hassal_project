<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\CarriesDevice;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    use CarriesDevice;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'login' => ['required', 'string'], // email or phone
            'password' => ['required', 'string'],
        ], $this->deviceRules());
    }
}
