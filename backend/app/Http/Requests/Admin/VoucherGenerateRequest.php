<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class VoucherGenerateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the 'abilities:admin' route middleware
    }

    public function rules(): array
    {
        return [
            'package_id' => ['required', 'integer', 'exists:internet_packages,id'],
            // Required, not nullable — generating a voucher now means
            // actually creating a live hotspot user on a specific
            // router (see Admin\VoucherController::generate()), which
            // is impossible without knowing which device to create it
            // on. The old "redeemable at any router" flexibility only
            // ever made sense for database bookkeeping, not a real
            // live-provisioned code.
            'router_id' => ['required', 'integer', 'exists:routers,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
