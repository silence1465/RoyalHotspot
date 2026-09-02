<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOrderPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the 'abilities:customer' route middleware
    }

    public function rules(): array
    {
        return [
            'transaction_id' => ['required', 'string', 'max:100'],
            'reference_used' => ['required', 'string', 'max:100'],
        ];
    }
}
