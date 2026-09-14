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
            'usage_policy' => ['required', Rule::in(['none', 'fup', 'data_cap'])],
            'fup_period' => ['required_if:usage_policy,fup', Rule::in(['daily', 'cycle'])],
            'data_allowance_bytes' => ['nullable', 'required_unless:usage_policy,none', 'integer', 'min:1048576'],
            'tier1_threshold_percent' => ['required_if:usage_policy,fup', 'integer', 'min:1', 'max:98'],
            'tier2_threshold_percent' => ['required_if:usage_policy,fup', 'integer', 'min:2', 'max:99', 'gt:tier1_threshold_percent'],
            'tier1_speed_percent' => ['required_if:usage_policy,fup', 'integer', 'min:1', 'max:100'],
            'tier2_speed_percent' => ['required_if:usage_policy,fup', 'integer', 'min:1', 'max:100', 'lte:tier1_speed_percent'],
            'tier3_speed_percent' => ['required_if:usage_policy,fup', 'integer', 'min:1', 'max:100', 'lte:tier2_speed_percent'],
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

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->boolean('available_to_guests') && $this->input('usage_policy') === 'fup') {
                $validator->errors()->add(
                    'available_to_guests',
                    'Dynamic FUP requires a registered customer. Guest packages may use a hard data cap instead.'
                );
            }

            $channel = $this->input('sales_channel')
                ?? $this->route('package')?->sales_channel
                ?? 'subscription';
            if ($this->input('usage_policy') !== 'none' && in_array($channel, ['voucher', 'both'], true)) {
                $validator->errors()->add(
                    'usage_policy',
                    'Tracked FUP and data-cap policies require live RouterOS fulfillment; imported voucher codes cannot be metered reliably.'
                );
            }
        });
    }
}
