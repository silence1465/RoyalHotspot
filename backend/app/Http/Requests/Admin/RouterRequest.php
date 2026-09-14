<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RouterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the 'abilities:admin' route middleware
    }

    public function rules(): array
    {
        // On update, api_password is optional — leaving it blank means
        // "keep the existing password". A blank string must never be
        // written through the `encrypted` cast (that would silently wipe
        // the real credential), so the controller strips it before saving.
        $isUpdate = $this->isMethod('put') || $this->isMethod('patch');

        // Manual routers (no VPS/WireGuard access at all — see
        // MikrotikService::run()'s central guard) shouldn't force an
        // admin to fill in fake connection details just to satisfy
        // validation. These fields are only required when actually live.
        $isLive = $this->input('connection_mode', 'live') === 'live';

        return [
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'router_ip' => ['nullable', 'ip'],
            'wireguard_ip' => [$isLive ? 'required' : 'nullable', 'ip'],
            'api_username' => [$isLive ? 'required' : 'nullable', 'string', 'max:255'],
            'api_password' => [$isLive && ! $isUpdate ? 'required' : 'nullable', 'string', 'min:4'],
            'api_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'api_ssl' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['online', 'offline', 'maintenance'])],
            'connection_mode' => ['nullable', Rule::in(['live', 'manual'])],
            'momo_enabled' => ['sometimes', 'boolean'],
            'paystack_enabled' => ['sometimes', 'boolean'],

            // Always optional, even on a live router — the automated
            // "Set Up Guest Portal" feature is opt-in, not every router
            // needs it (an admin might set up the portal manually in
            // Winbox instead). See 2024_02_01_000019 migration for why
            // this is a separate credential from api_username/password.
            'provisioning_api_username' => ['nullable', 'string', 'max:255'],
            'provisioning_api_password' => ['nullable', 'string', 'min:4'],

            // RouterOS address-pool name to assign on every hotspot user
            // profile created for this router (see
            // MikrotikService::ensureHotspotUserProfile()). Must already
            // exist on the router — /ip pool add — this app never creates
            // pools, only references one by name.
            'address_pool' => ['nullable', 'string', 'max:255'],
            'hotspot_login_host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
        ];
    }
}
