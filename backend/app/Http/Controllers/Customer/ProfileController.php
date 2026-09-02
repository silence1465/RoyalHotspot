<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $customer = $request->user();

        $validated = $request->validate([
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => [
                'sometimes', 'required', 'string', 'max:20',
                Rule::unique('customers', 'phone')->ignore($customer->id),
            ],
            // Password changes deserve their own explicit flow (current
            // password confirmation) rather than sneaking into a general
            // profile PUT — deliberately not included here.
        ]);

        $customer->update($validated);

        return response()->json($customer->fresh());
    }
}
