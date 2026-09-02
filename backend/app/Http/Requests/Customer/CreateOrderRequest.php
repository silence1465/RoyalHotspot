<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the 'abilities:customer' route middleware
    }

    public function rules(): array
    {
        return [
            'package_id' => [
                'required',
                'integer',
                Rule::exists('internet_packages', 'id')->where('status', 'active'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'package_id.exists' => 'This package is no longer available.',
        ];
    }
}
