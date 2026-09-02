<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Payment;
use App\Services\PaystackService;
use App\Services\PurchaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function paystack(Request $request, PaystackService $paystack, PurchaseService $purchaseService)
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

        DB::transaction(function () use ($reference, $paystack, $purchaseService) {
            $payment = Payment::where('reference', $reference)->lockForUpdate()->first();

            if (! $payment) {
                Log::warning("Paystack webhook: no payment found for reference {$reference}.");
                return;
            }

            if ($payment->status === 'successful') {
                return;
            }

            $verification = $paystack->verifyTransaction($reference);
            $verifiedData = $verification['data'] ?? null;

            if (! $verification['success'] || ($verifiedData['status'] ?? null) !== 'success') {
                $payment->update([
                    'status' => 'failed',
                    'provider_response' => json_encode($verification),
                ]);
                return;
            }

            $expectedAmount = (int) round($payment->amount * 100);
            $verifiedAmount = (int) ($verifiedData['amount'] ?? 0);

            if ($verifiedAmount !== $expectedAmount) {
                Log::critical("Paystack webhook: amount mismatch for {$reference}. Expected {$expectedAmount}, got {$verifiedAmount}.");
                $payment->update([
                    'status' => 'failed',
                    'provider_response' => json_encode($verifiedData),
                ]);
                ActivityLog::record(
                    'payment.amount_mismatch',
                    "Payment {$reference}: expected {$expectedAmount}, verified {$verifiedAmount}.",
                    ['customer_id' => $payment->customer_id]
                );
                return;
            }

            $payment->update([
                'status' => 'successful',
                'channel' => $verifiedData['channel'] ?? null,
                'paid_at' => now(),
                'provider_response' => json_encode($verifiedData),
            ]);

            if ($payment->purchase) {
                $purchaseService->verifyAndFulfill($payment->purchase, 'paystack_webhook');
            }

            ActivityLog::record(
                'payment.successful',
                "Payment {$reference} confirmed successful via webhook.",
                ['customer_id' => $payment->customer_id]
            );
        });

        return response()->json(['message' => 'ok']);
    }
}
