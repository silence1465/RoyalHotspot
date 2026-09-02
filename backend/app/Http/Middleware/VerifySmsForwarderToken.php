<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The SMS Forwarder app is not a customer or an admin — it's a trusted
 * device/app relaying SMS content. Sanctum's customer/admin token
 * abilities don't fit this actor at all, so it gets its own auth: a
 * long, random shared secret configured in both this app's .env
 * (SMS_FORWARDER_TOKEN) and the forwarder app's own config.
 *
 * hash_equals() is used deliberately instead of `===` — a naive string
 * comparison leaks timing information about how many leading characters
 * matched, which is a real (if narrow) attack vector against secret
 * comparison.
 */
class VerifySmsForwarderToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.sms_forwarder.token');

        if (! $expected) {
            // Misconfiguration, not a client error — fail closed rather
            // than silently accepting every request because the server
            // forgot to set a token.
            return response()->json(['message' => 'SMS Forwarder integration is not configured.'], 503);
        }

        $provided = $request->bearerToken() ?? $request->header('X-Forwarder-Token');

        // The Android forwarder's dedicated heartbeat screen only accepts
        // a URL and cannot attach custom headers. Permit its shared secret
        // in the query string for this one health-check endpoint; payment
        // SMS delivery remains header-only so message data and payment
        // processing cannot be triggered through a tokenized URL.
        if (! $provided && $request->routeIs('sms-forwarder.heartbeat')) {
            $provided = $request->query('token');
        }

        if (! $provided || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Invalid or missing forwarder token.'], 401);
        }

        return $next($request);
    }
}
