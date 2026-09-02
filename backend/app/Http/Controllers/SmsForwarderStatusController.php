<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Support\Carbon;

/**
 * Deliberately public, no auth — both the registered-customer payment
 * page and the unauthenticated guest one need this, and there's nothing
 * sensitive in "is the SMS relay phone currently reachable".
 */
class SmsForwarderStatusController extends Controller
{
    public function show()
    {
        $lastHeartbeat = SystemSetting::get('sms_forwarder_last_heartbeat_at');
        $thresholdMinutes = (int) (SystemSetting::get('sms_heartbeat_threshold_minutes') ?? 5);

        $online = $lastHeartbeat
            && Carbon::parse($lastHeartbeat)->greaterThan(now()->subMinutes($thresholdMinutes));

        return response()->json([
            'online' => (bool) $online,
            'last_seen' => $lastHeartbeat,
        ]);
    }
}
