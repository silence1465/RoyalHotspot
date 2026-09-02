<?php

namespace App\Http\Controllers;

use App\Models\PaymentSmsLog;
use App\Models\SystemSetting;
use App\Services\PaymentMatchingService;
use App\Services\SmsBodyParser;
use Illuminate\Http\Request;

/**
 * NOT under /admin or /customer — this is a machine actor (the SMS
 * Forwarder app), authenticated via the 'sms-forwarder' middleware
 * (shared secret, see VerifySmsForwarderToken), not Sanctum.
 */
class PaymentSmsWebhookController extends Controller
{
    /**
     * A periodic ping from the SMS Forwarder app, separate from actual
     * SMS relaying — lets the system distinguish "no payments came in"
     * (quiet but healthy) from "the phone is dead/offline/uninstalled"
     * (silent because nothing is running at all), which look identical
     * from SMS volume alone. See SmsForwarderStatusController for the
     * read side admins and customers actually check.
     */
    public function heartbeat(Request $request)
    {
        SystemSetting::set('sms_forwarder_last_heartbeat_at', now()->toIso8601String());

        return response()->json(['status' => 'ok']);
    }

    public function receive(Request $request, SmsBodyParser $parser, PaymentMatchingService $matcher)
    {
        $validated = $request->validate([
            // Field names deliberately generic/common ones — most SMS
            // forwarder apps use "message"/"from"/"to"/"timestamp" or a
            // close variant. Adjust here if your specific forwarder app
            // sends a different shape; the rest of the pipeline doesn't
            // care once this normalizes it.
            'message' => ['nullable', 'required_without:text', 'string'],
            'text' => ['nullable', 'required_without:message', 'string'],
            'from' => ['nullable', 'string', 'max:100'],
            'to' => ['nullable', 'string', 'max:50'],
            'timestamp' => ['nullable', 'date'],
            'sentStamp' => ['nullable', 'numeric'],
            'receivedStamp' => ['nullable', 'numeric'],
            'sim' => ['nullable', 'string', 'max:50'],
        ]);

        $message = $validated['message'] ?? $validated['text'];
        $parsed = $parser->parse($message);

        $log = PaymentSmsLog::create([
            'raw_body' => $message,
            'sender' => $validated['from'] ?? null,
            'recipient' => $validated['to'] ?? $validated['sim'] ?? null,
            'transaction_id' => $parsed['transaction_id'],
            'amount' => $parsed['amount'],
            'parsed_phone' => $parsed['phone'],
            'parsed_reference' => $parsed['reference'],
            'received_at' => $validated['timestamp'] ?? now(),
            'verification_status' => 'unmatched', // set for real by processIncomingSms() below
            // Whatever else the forwarder sent beyond the fields we
            // explicitly validated — kept for debugging without needing
            // a schema change every time a forwarder app's payload shifts.
            'forwarder_meta' => $request->except(['message', 'text', 'from', 'to', 'timestamp']),
        ]);

        $matcher->processIncomingSms($log);

        // Deliberately minimal response — this is a relay app, not a
        // trusted display surface, so it never gets order/customer
        // details back.
        return response()->json(['status' => 'received', 'id' => $log->id]);
    }
}
