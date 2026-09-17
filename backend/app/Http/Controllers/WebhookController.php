<?php

namespace App\Http\Controllers;

use App\Services\PaystackPaymentVerifier;
use App\Services\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function paystack(Request $request, PaystackService $paystack, PaystackPaymentVerifier $verifier)
    {
        $rawBody = $request->getContent();
        $signature = $request->header('X-Paystack-Signature');

        if (! $paystack->verifyWebhookSignature($rawBody, $signature)) {
            Log::warning('Paystack webhook: invalid signature.');

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($rawBody, true);
        $event = $payload['event'] ?? null;

        if ($event !== 'charge.success') {
            return response()->json(['message' => 'Event ignored.']);
        }

        $reference = $payload['data']['reference'] ?? null;

        if (! $reference) {
            return response()->json(['message' => 'Missing reference.'], 400);
        }

        $result = $verifier->verify($reference);
        if (! $result['found']) {
            Log::warning("Paystack webhook: no payment found for reference {$reference}.");
        }

        return response()->json(['message' => 'ok']);
    }
}
