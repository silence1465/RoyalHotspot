<?php

namespace App\Services;

use App\Jobs\SendVoucherPurchaseEmailJob;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

/**
 * Called from every path that can confirm a Royal WiFi payment: the SMS
 * auto-match (PaymentMatchingService), the customer's manual "Already
 * Paid" fallback, an admin's manual approval, and the PDF-import backfill
 * (a newly-imported batch may unblock orders that were stuck waiting for
 * inventory). All of them funnel through here so the locking/assignment
 * logic exists in exactly one place.
 */
class VoucherAssignmentService
{
    /**
     * Mark an order verified and attempt to assign a voucher immediately.
     * Idempotent — safe to call more than once for the same order (e.g. a
     * duplicate SMS arriving after the order is already verified) without
     * double-assigning or overwriting who verified it first.
     */
    public function verifyAndAssign(
        Order $order,
        string $method,
        ?int $adminUserId = null,
        ?string $transactionId = null
    ): Order {
        $order = DB::transaction(function () use ($order, $method, $adminUserId, $transactionId) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            // Already past 'pending'/'processing'/'manual_review' — most
            // likely a duplicate SMS or a second manual-verify click.
            // Don't re-verify, don't re-run assignment logic.
            if (! in_array($locked->status, ['pending', 'processing', 'manual_review'], true)) {
                return $locked;
            }

            $locked->update([
                'status' => 'verified',
                'verified_at' => now(),
                'verification_method' => $method,
                'verified_by' => $adminUserId,
                'momo_transaction_id' => $transactionId ?? $locked->momo_transaction_id,
            ]);

            ActivityLog::record(
                'order.verified',
                "Order {$locked->reference} verified via {$method}.",
                $adminUserId ? ['user_id' => $adminUserId] : ['customer_id' => $locked->customer_id]
            );

            return $locked;
        });

        return $this->attemptAssignment($order);
    }

    /**
     * Try to lock and hand out an available voucher for this order's
     * package. Safe to call on an already-'verified' order with no
     * voucher yet (the "waiting for inventory" case) — this is exactly
     * what the PDF-import backfill calls once new stock arrives.
     *
     * Returns the order either way — check $order->voucher_id to see
     * whether assignment actually happened.
     */
    public function attemptAssignment(Order $order): Order
    {
        $result = DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if ($locked->status !== 'verified' || $locked->voucher_id !== null) {
                return $locked; // nothing to do — not verified yet, or already has one
            }

            // Oldest-available-first (FIFO) is arbitrary but fair, and
            // avoids any pattern that could look like cherry-picking
            // specific codes. lockForUpdate() here is what makes "a
            // voucher must never be assigned to two customers" actually
            // true under concurrency — a second simultaneous request for
            // the same package blocks on this row lock rather than
            // reading a stale 'available' status.
            $voucher = Voucher::where('package_id', $locked->package_id)
                ->where('status', 'available')
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->oldest('id')
                ->lockForUpdate()
                ->first();

            if (! $voucher) {
                // Payment stays safely verified — see class docblock and
                // spec: "Do NOT mark the customer's payment as lost."
                // Surfaced to admins via the orders list (status=verified,
                // voucher_id=null is the "waiting for inventory" state —
                // no separate column needed) and this activity log entry.
                ActivityLog::record(
                    'order.awaiting_inventory',
                    "Order {$locked->reference} verified but no voucher available for package #{$locked->package_id}.",
                    ['customer_id' => $locked->customer_id]
                );

                return $locked;
            }

            $voucher->update([
                'status' => 'assigned',
                'assigned_to' => $locked->customer_id,
                'assigned_at' => now(),
                'order_id' => $locked->id,
            ]);

            $locked->update([
                'voucher_id' => $voucher->id,
                'status' => 'voucher_assigned',
            ]);

            ActivityLog::record(
                'voucher.assigned',
                "Voucher {$voucher->code} assigned to order {$locked->reference}.",
                ['customer_id' => $locked->customer_id]
            );

            return $locked;
        });

        // Deliberately OUTSIDE the transaction above — queuing an email is
        // a completely separate concern from the DB transaction that
        // assigned the voucher, and must never be able to roll it back
        // (see spec: "Email failure must not roll back a successful
        // payment/voucher assignment").
        if ($result->status === 'voucher_assigned') {
            SendVoucherPurchaseEmailJob::dispatch($result->id);
            $result->update(['status' => 'completed']);
        }

        return $result;
    }
}
