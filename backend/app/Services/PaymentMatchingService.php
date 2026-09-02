<?php

namespace App\Services;

use App\Models\PaymentSmsLog;
use App\Models\Purchase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

class PaymentMatchingService
{
    public function __construct(private PurchaseService $purchaseService)
    {
    }

    public function processIncomingSms(PaymentSmsLog $log): void
    {
        if ($log->transaction_id) {
            $alreadyClaimed = PaymentSmsLog::where('transaction_id', $log->transaction_id)
                ->where('id', '!=', $log->id)
                ->whereNotNull('matched_purchase_id')
                ->exists();

            if ($alreadyClaimed) {
                $log->update(['verification_status' => 'duplicate']);
                return;
            }
        }

        if (! $log->parsed_reference) {
            $log->update(['verification_status' => 'unmatched']);
            return;
        }

        $purchase = Purchase::where('reference', $log->parsed_reference)
            ->whereIn('status', ['pending', 'processing', 'manual_review'])
            ->first();

        if (! $purchase) {
            $log->update(['verification_status' => 'unmatched']);
            return;
        }

        if (! $this->amountMatches($log->amount, $purchase->amount)) {
            $log->update(['verification_status' => 'manual_review', 'matched_purchase_id' => $purchase->id]);
            $purchase->update([
                'status' => 'manual_review',
                'admin_notes' => "SMS reference matched but amount differed (purchase: GHS {$purchase->amount}, SMS: GHS {$log->amount}).",
            ]);
            return;
        }

        $lockKey = 'momo-transaction:'.hash('sha256', (string) ($log->transaction_id ?: $log->id));
        Cache::lock($lockKey, 15)->block(5, function () use ($log, $purchase) {
            try {
                $this->purchaseService->verifyAndFulfill($purchase, 'sms_auto', transactionId: $log->transaction_id);
                $log->update(['verification_status' => 'matched', 'matched_purchase_id' => $purchase->id]);
            } catch (QueryException $exception) {
                if ((string) $exception->getCode() !== '23000') throw $exception;
                $log->update(['verification_status' => 'duplicate']);
            }
        });
    }

    public function attemptManualMatch(Purchase $purchase, string $transactionId, string $referenceUsed): array
    {
        if (! hash_equals($purchase->reference, trim($referenceUsed))) {
            return ['success' => false, 'message' => 'The payment reference does not match this purchase.'];
        }

        $log = PaymentSmsLog::where('transaction_id', trim($transactionId))
            ->where('parsed_reference', $purchase->reference)
            ->where(function ($q) use ($purchase) {
                $q->whereNull('matched_purchase_id')->orWhere('matched_purchase_id', $purchase->id);
            })
            ->first();

        if (! $log) {
            // Escalating on the very first attempt would flood admin with
            // false alarms — a customer typing their transaction ID
            // within seconds of paying, before the SMS relay has caught
            // up, is a completely normal race, not something needing a
            // human. Only escalate once the purchase has had a
            // reasonable window to auto-match on its own.
            if ($purchase->created_at->lt(now()->subMinutes(3))) {
                $purchase->update([
                    'status' => 'manual_review',
                    'admin_notes' => trim(($purchase->admin_notes ? $purchase->admin_notes . "\n" : '')
                        . "Customer submitted transaction ID '{$transactionId}' (reference used: '{$referenceUsed}') but no matching SMS was found — needs manual review."),
                ]);

                return [
                    'success' => false,
                    'message' => 'We couldn\'t automatically confirm this yet — it\'s been flagged for our team to check manually. We\'ll follow up shortly.',
                ];
            }

            return [
                'success' => false,
                'message' => 'No matching payment found yet. If you\'ve already paid, please wait a few minutes for confirmation or contact support.',
            ];
        }

        if ($log->matched_purchase_id && (int) $log->matched_purchase_id !== $purchase->id) {
            return [
                'success' => false,
                'message' => 'This transaction has already been used to verify a different purchase.',
            ];
        }

        if (! $this->amountMatches($log->amount, $purchase->amount)) {
            $log->update(['verification_status' => 'manual_review', 'matched_purchase_id' => $purchase->id]);
            $purchase->update([
                'status' => 'manual_review',
                'admin_notes' => "Customer-submitted match found but amount differed (purchase: GHS {$purchase->amount}, SMS: GHS {$log->amount}).",
            ]);

            return [
                'success' => false,
                'message' => 'We found a possible match, but the amount doesn\'t line up. This has been flagged for manual review — we\'ll follow up shortly.',
            ];
        }

        $lockKey = 'momo-transaction:'.hash('sha256', trim($transactionId));
        try {
            Cache::lock($lockKey, 15)->block(5, function () use ($log, $purchase, $transactionId) {
                $this->purchaseService->verifyAndFulfill($purchase, 'manual_transaction_id', transactionId: trim($transactionId));
                $log->update(['verification_status' => 'matched', 'matched_purchase_id' => $purchase->id]);
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') throw $exception;
            return ['success' => false, 'message' => 'This transaction has already been used for another purchase.'];
        }

        return ['success' => true, 'message' => 'Payment verified!'];
    }

    protected function amountMatches(?float $smsAmount, float $purchaseAmount): bool
    {
        if ($smsAmount === null) {
            return false;
        }

        return bccomp((string) $smsAmount, (string) $purchaseAmount, 2) === 0;
    }
}
