<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // registration is a public endpoint
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'unique:customers,phone'],
            'email' => ['required', 'email', 'max:255', 'unique:customers,email'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:customers,username'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.unique' => 'An account with this phone number already exists.',
            'username.unique' => 'That username is already taken.',
            'email.unique' => 'An account with this email address already exists.',
        ];
    }
}
