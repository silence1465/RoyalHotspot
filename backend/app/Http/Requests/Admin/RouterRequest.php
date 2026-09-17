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
            'routeros_version' => ['nullable', 'string', 'max:32', 'regex:/^\d+(?:\.\d+){1,3}$/'],
            'isp_failover_enabled' => ['sometimes', 'boolean'],
            'isp_failback_enabled' => ['sometimes', 'boolean'],
            'isps' => ['sometimes', 'array', 'max:16'],
            'isps.*.id' => ['nullable', 'integer'],
            'isps.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'isps.*.wan_interface' => ['required', 'string', 'max:100', 'distinct:ignore_case', 'regex:/^[A-Za-z0-9_.:+-]+$/'],
            'isps.*.gateway' => ['required', 'ip'],
            'isps.*.routing_table' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'isps.*.connection_mark' => ['nullable', 'string', 'max:100', 'distinct:ignore_case', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'isps.*.monthly_capacity_gb' => ['nullable', 'numeric', 'min:0.001', 'max:1048576'],
            'isps.*.subscriber_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'isps.*.priority' => ['required', 'integer', 'min:1', 'max:65535'],
            'isps.*.enabled' => ['required', 'boolean'],
            'isps.*.session_monitoring_enabled' => ['sometimes', 'boolean'],
            'isps.*.session_protection_enabled' => ['sometimes', 'boolean'],
            'isps.*.stale_cleanup_enabled' => ['sometimes', 'boolean'],
            'isps.*.emergency_cleanup_enabled' => ['sometimes', 'boolean'],
            'isps.*.session_soft_limit' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'isps.*.session_hard_limit' => ['nullable', 'integer', 'min:2', 'max:10000000'],
            'isps.*.session_emergency_limit' => ['nullable', 'integer', 'min:3', 'max:10000000'],
            'isps.*.max_tcp_sessions_per_client' => ['nullable', 'integer', 'min:10', 'max:1000000'],
            'isps.*.max_udp_sessions_per_client' => ['nullable', 'integer', 'min:5', 'max:1000000'],
            'isps.*.max_total_sessions_per_client' => ['nullable', 'integer', 'min:10', 'max:1000000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ($this->input('isps', []) as $index => $isp) {
                $monitoring = filter_var($isp['session_monitoring_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                if ($monitoring && blank($isp['connection_mark'] ?? null)) {
                    $validator->errors()->add("isps.{$index}.connection_mark", 'A RouterOS connection mark is required when monitoring is enabled.');
                }

                $soft = isset($isp['session_soft_limit']) && $isp['session_soft_limit'] !== '' ? (int) $isp['session_soft_limit'] : null;
                $hard = isset($isp['session_hard_limit']) && $isp['session_hard_limit'] !== '' ? (int) $isp['session_hard_limit'] : null;
                $emergency = isset($isp['session_emergency_limit']) && $isp['session_emergency_limit'] !== '' ? (int) $isp['session_emergency_limit'] : null;
                if ($monitoring && ($soft === null || $hard === null || $emergency === null)) {
                    $validator->errors()->add("isps.{$index}.session_soft_limit", 'Soft, hard and emergency limits are required when monitoring is enabled.');
                } elseif ($soft !== null && $hard !== null && $emergency !== null && ! ($soft < $hard && $hard < $emergency)) {
                    $validator->errors()->add("isps.{$index}.session_soft_limit", 'Limits must increase in this order: soft < hard < emergency.');
                }

                if (filter_var($isp['session_protection_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    || filter_var($isp['stale_cleanup_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    || filter_var($isp['emergency_cleanup_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $validator->errors()->add("isps.{$index}.session_protection_enabled", 'Traffic protection and cleanup remain locked until this ISP connection mark is validated on the physical router.');
                }
            }
        });
    }
}
