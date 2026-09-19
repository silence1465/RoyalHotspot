<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class PaystackPaymentVerifier
{
    public function __construct(
        protected PaystackService $paystack,
        protected PurchaseService $purchaseService
    ) {}

    public function verify(string $reference, ?int $customerId = null): array
    {
        $payment = Payment::with('purchase')
            ->where('reference', $reference)
            ->where('provider', 'paystack')
            ->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId))
            ->first();

        if (! $payment) {
            return ['found' => false, 'success' => false, 'status' => 'not_found'];
        }

        if ($payment->status === 'successful') {
            return $this->result($payment->fresh('purchase'));
        }

        $verification = $this->paystack->verifyTransaction($reference);
        $verified = $verification['data'] ?? null;

        // Network/provider errors and payments that Paystack has not yet
        // finalized remain retryable. Never turn a temporary outage into a
        // permanently failed paid order.
        if (! $verification['success'] || ($verified['status'] ?? null) !== 'success') {
            return [
                'found' => true,
                'success' => false,
                'status' => 'pending',
                'message' => 'Paystack has not confirmed this payment yet.',
            ];
        }

        $expectedAmount = (int) round(((float) $payment->amount) * 100);
        $verifiedAmount = (int) ($verified['amount'] ?? 0);
        $verifiedReference = (string) ($verified['reference'] ?? $reference);

        if ($verifiedAmount !== $expectedAmount || ! hash_equals($reference, $verifiedReference)) {
            DB::transaction(function () use ($payment, $verified, $expectedAmount, $verifiedAmount) {
                $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'successful') {
                    return;
                }
                $locked->update([
                    'status' => 'failed',
                    'provider_response' => json_encode($verified),
                ]);
                ActivityLog::record(
                    'payment.amount_mismatch',
                    "Payment {$locked->reference}: expected {$expectedAmount}, verified {$verifiedAmount}.",
                    ['customer_id' => $locked->customer_id]
                );
            });

            return [
                'found' => true,
                'success' => false,
                'status' => 'failed',
                'message' => 'The verified payment details do not match this purchase.',
            ];
        }

        return DB::transaction(function () use ($payment, $verified) {
            $locked = Payment::with('purchase')->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'successful') {
                $locked->update([
                    'status' => 'successful',
                    'channel' => $verified['channel'] ?? null,
                    'paid_at' => now(),
                    'provider_response' => json_encode($verified),
                ]);

                if ($locked->purchase) {
                    // Keep the existing persisted enum value. It represents
                    // Paystack's server-side verification path whether the
                    // trigger was the webhook or the authenticated callback.
                    $this->purchaseService->verifyAndFulfill($locked->purchase, 'paystack_webhook');
                }

                ActivityLog::record(
                    'payment.successful',
                    "Payment {$locked->reference} confirmed successful with Paystack.",
                    ['customer_id' => $locked->customer_id]
                );
            }

            return $this->result($locked->fresh('purchase'));
        });
    }

    protected function result(Payment $payment): array
    {
        return [
            'found' => true,
            'success' => $payment->status === 'successful',
            'status' => $payment->status,
            'purchase_status' => $payment->purchase?->status,
            'message' => $payment->status === 'successful'
                ? 'Payment verified. Your internet package is being prepared.'
                : 'Paystack has not confirmed this payment yet.',
        ];
    }
}
