<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Purchase;
use App\Models\RouterIsp;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    private const OPERATING_MODES = ['normal', 'data_cap', 'user_cap'];

    private const MASKED_SECRET = '••••••••';

    /**
     * The known, editable settings — anything outside this list is
     * silently ignored on update() rather than allowing arbitrary
     * key-value writes through this endpoint.
     */
    private const KNOWN_KEYS = [
        'business_name',
        'business_phone',
        'default_router_id',
        'paystack_public_key',
        'sms_enabled',
        'grace_period_minutes',
        'auto_suspend_enabled',
        // Royal WiFi extension — never hardcoded, see Customer\OrderController::paymentPagePayload()
        'momo_number',
        'momo_account_name',
        'order_expiry_minutes',
        'low_stock_threshold',
        'paystack_enabled',
        'momo_enabled',
        'sms_heartbeat_threshold_minutes',
        'telegram_bot_token',
        'telegram_chat_id',
    ];

    public function index()
    {
        $settings = [];

        foreach (self::KNOWN_KEYS as $key) {
            $settings[$key] = SystemSetting::get($key);
        }
        $settings['paystack_enabled'] = $this->gatewayEnabled('paystack');
        $settings['momo_enabled'] = $this->gatewayEnabled('momo');
        if (filled($settings['telegram_bot_token'] ?? null)) {
            $settings['telegram_bot_token'] = self::MASKED_SECRET;
        }

        return response()->json($settings);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'business_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'default_router_id' => ['sometimes', 'nullable', 'integer', 'exists:routers,id'],
            'paystack_public_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sms_enabled' => ['sometimes', 'nullable', 'boolean'],
            'grace_period_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10080'], // 7 days ceiling
            'auto_suspend_enabled' => ['sometimes', 'nullable', 'boolean'],
            // Royal WiFi extension
            'momo_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'momo_account_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'order_expiry_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:1440'],
            'low_stock_threshold' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'paystack_enabled' => ['sometimes', 'boolean'],
            'momo_enabled' => ['sometimes', 'boolean'],
            'sms_heartbeat_threshold_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:60'],
            'telegram_bot_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $paystackEnabled = array_key_exists('paystack_enabled', $validated)
            ? (bool) $validated['paystack_enabled']
            : $this->gatewayEnabled('paystack');
        $momoEnabled = array_key_exists('momo_enabled', $validated)
            ? (bool) $validated['momo_enabled']
            : $this->gatewayEnabled('momo');

        if (! $paystackEnabled && ! $momoEnabled) {
            return response()->json([
                'message' => 'At least one payment gateway must remain enabled.',
                'errors' => ['payment_gateways' => ['Enable Paystack or direct Mobile Money before saving.']],
            ], 422);
        }

        foreach ($validated as $key => $value) {
            if ($key === 'telegram_bot_token' && $value === self::MASKED_SECRET) {
                continue;
            }
            SystemSetting::set($key, $value);
        }

        ActivityLog::record(
            'settings.updated',
            'System settings updated: '.implode(', ', array_keys($validated)),
            ['user_id' => $request->user()->id]
        );

        $settings = [];
        foreach (self::KNOWN_KEYS as $key) {
            $settings[$key] = SystemSetting::get($key);
        }
        $settings['paystack_enabled'] = $this->gatewayEnabled('paystack');
        $settings['momo_enabled'] = $this->gatewayEnabled('momo');
        if (filled($settings['telegram_bot_token'] ?? null)) {
            $settings['telegram_bot_token'] = self::MASKED_SECRET;
        }

        return response()->json($settings);
    }

    public function operatingMode()
    {
        $mode = (string) SystemSetting::get('operating_mode', 'normal');

        return response()->json([
            'mode' => in_array($mode, self::OPERATING_MODES, true) ? $mode : 'normal',
            'scope' => 'system',
            'available_modes' => self::OPERATING_MODES,
        ]);
    }

    public function updateOperatingMode(Request $request)
    {
        $data = $request->validate([
            'mode' => ['required', 'string', 'in:'.implode(',', self::OPERATING_MODES)],
        ]);

        abort_unless($request->user()->isSuperAdmin(), 403, 'Only a super administrator can change system operating mode.');
        if ($data['mode'] !== 'normal' && ! RouterIsp::where('enabled', true)
            ->whereNotNull('monthly_capacity_bytes')->where('monthly_capacity_bytes', '>', 0)->exists()) {
            return response()->json([
                'message' => 'Configure at least one enabled ISP with monthly capacity before enabling this mode.',
            ], 422);
        }
        if ($data['mode'] === 'user_cap' && ! RouterIsp::where('enabled', true)
            ->whereNotNull('subscriber_limit')->where('subscriber_limit', '>', 0)->exists()) {
            return response()->json([
                'message' => 'Configure a subscriber limit on at least one enabled ISP before enabling User Cap mode.',
            ], 422);
        }
        if ($data['mode'] !== 'normal' && Purchase::active()->whereNull('router_isp_id')->exists()) {
            return response()->json([
                'message' => 'Capacity mode cannot be enabled while existing active purchases have no ISP allocation. Let them expire or migrate them first.',
            ], 422);
        }

        SystemSetting::set('operating_mode', $data['mode']);

        ActivityLog::record(
            'settings.operating_mode_updated',
            "Operating mode changed to {$data['mode']}.",
            ['user_id' => $request->user()->id]
        );

        return response()->json(['mode' => $data['mode']]);
    }

    private function gatewayEnabled(string $gateway): bool
    {
        return filter_var(SystemSetting::get("{$gateway}_enabled", '1'), FILTER_VALIDATE_BOOLEAN);
    }
}
