<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the 'abilities:admin' route middleware
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'duration_value' => ['required', 'integer', 'min:1'],
            'duration_unit' => ['required', Rule::in(['minutes', 'hours', 'days', 'weeks', 'months'])],
            'momo_bonus_value' => ['nullable', 'integer', 'min:0', 'max:87600'],
            'momo_bonus_unit' => ['required', Rule::in(['hours', 'days'])],
            'speed_limit' => ['nullable', 'string', 'max:50'],
            'data_limit' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],

            // Which purchase flow(s) this package appears in — 'subscription'
            // (Paystack, router auto-provisioned), 'voucher' (MoMo,
            // PDF-imported code), or 'both' (customer picks at purchase
            // time). See docs/DATABASE_SCHEMA.md and the unified
            // BuyInternet.jsx frontend flow. Defaults to 'subscription' to
            // preserve existing behavior for packages created before this
            // field existed.
            'sales_channel' => ['nullable', Rule::in(['subscription', 'voucher', 'both'])],
            'available_to_guests' => ['nullable', 'boolean'],

            // Optional per-router RouterOS profile name mapping — see
            // router_package_profiles in docs/DATABASE_SCHEMA.md. A
            // package can be saved without any mapping yet (e.g. before
            // any router is configured for it), but activation will fail
            // on a given router until a profile is mapped for it.
            'profiles' => ['nullable', 'array'],
            'profiles.*.router_id' => ['required_with:profiles', 'exists:routers,id'],
            'profiles.*.profile_name' => ['required_with:profiles', 'string', 'max:255'],
            'profiles.*.shared_users' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }
}
