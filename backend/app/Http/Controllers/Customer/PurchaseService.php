<?php

namespace App\Http\Controllers\Customer;

use App\Jobs\ActivateHotspotUserJob;
use App\Jobs\SendVoucherPurchaseEmailJob;
use App\Models\ActivityLog;
use App\Models\HotspotUser;
use App\Models\Purchase;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

/**
 * The single fulfillment service for all purchases, replacing both
 * SubscriptionService (Paystack + live router) and
 * VoucherAssignmentService (MoMo + voucher). Every payment confirmation
 * path — Paystack webhook, MoMo SMS auto-match, customer "Already Paid"
 * fallback, admin manual approval — funnels through here.
 *
 * QUEUEING: a customer can only have one ACTIVE purchase per router at
 * a time. A second purchase on the same router while one is already
 * active gets paid and verified normally, but fulfillment is
 * deliberately held back — status 'queued' — until the customer
 * explicitly activates it (see activateQueuedPurchase()), which only
 * succeeds once the currently active one has actually expired. There is
 * deliberately no "activate on a second device" bypass — one login per
 * customer per router, full stop.
 */
class PurchaseService
{
    public function verifyAndFulfill(
        Purchase $purchase,
        string $verificationMethod,
        ?int $adminUserId = null,
        ?string $transactionId = null
    ): Purchase {
        $purchase = DB::transaction(function () use ($purchase, $verificationMethod, $adminUserId, $transactionId) {
            $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->first();

            if (! in_array($locked->status, ['pending', 'processing', 'manual_review'], true)) {
                return $locked;
            }

            $locked->update([
                'status' => 'verified',
                'verified_at' => now(),
                'verification_method' => $verificationMethod,
                'verified_by' => $adminUserId,
                'momo_transaction_id' => $transactionId ?? $locked->momo_transaction_id,
            ]);

            ActivityLog::record(
                'purchase.verified',
                "Purchase {$locked->reference} verified via {$verificationMethod}.",
                $adminUserId ? ['user_id' => $adminUserId] : ['customer_id' => $locked->customer_id]
            );

            return $locked;
        });

        return $this->fulfill($purchase);
    }

    /**
     * If the customer already has another active purchase on this same
     * router, this queues instead of fulfilling — unless $forceActivate
     * is true, which only the customer's own explicit "Activate" action
     * (or the admin equivalent) should ever pass.
     */
    public function fulfill(Purchase $purchase, bool $forceActivate = false): Purchase
    {
        if (! in_array($purchase->status, ['verified', 'queued'], true)) {
            return $purchase;
        }

        if (! $forceActivate && $purchase->customer_id && $this->hasActiveOnSameRouter($purchase)) {
            if ($purchase->status !== 'queued') {
                $purchase->update(['status' => 'queued']);
                ActivityLog::record(
                    'purchase.queued',
                    "Purchase {$purchase->reference} queued — customer already has an active purchase on this router.",
                    ['customer_id' => $purchase->customer_id]
                );
            }

            return $purchase->fresh();
        }

        if ($purchase->fulfillment_type === 'live') {
            return $this->fulfillLive($purchase);
        }

        return $this->fulfillVoucher($purchase);
    }

    /**
     * Customer-facing entry point for activating something 'queued'.
     * Blocked outright if something is still active on this router —
     * no bypass. Once that one expires, expirePurchase() auto-activates
     * the next queued purchase on its own.
     */
    public function activateQueuedPurchase(Purchase $purchase): array
    {
        if ($purchase->status !== 'queued') {
            return ['success' => false, 'message' => 'This purchase is not waiting to be activated.'];
        }

        if ($this->hasActiveOnSameRouter($purchase)) {
            return [
                'success' => false,
                'message' => 'You already have an active subscription on this router. It will activate automatically once that one expires.',
            ];
        }

        $this->fulfill($purchase, forceActivate: true);

        return ['success' => true, 'message' => 'Activated.'];
    }

    protected function hasActiveOnSameRouter(Purchase $purchase): bool
    {
        return Purchase::where('customer_id', $purchase->customer_id)
            ->where('router_id', $purchase->router_id)
            ->where('id', '!=', $purchase->id)
            ->active()
            ->exists();
    }

    protected function fulfillLive(Purchase $purchase): Purchase
    {
        return DB::transaction(function () use ($purchase) {
            $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->first();

            if (! in_array($locked->status, ['verified', 'queued'], true)) {
                return $locked;
            }

            $now = now();
            $durationMinutes = $this->calculateDurationMinutes($locked);

            $locked->update([
                'status' => 'active',
                'starts_at' => $now,
                'expires_at' => $now->copy()->addMinutes($durationMinutes),
            ]);

            if ($locked->customer_id) {
                $locked->customer->update([
                    'status' => 'active',
                    'current_purchase_id' => $locked->id,
                ]);
            }

            ActivateHotspotUserJob::dispatch($locked->id);

            ActivityLog::record(
                'purchase.activated',
                "Purchase {$locked->reference} activated (live fulfillment).",
                $locked->customer_id ? ['customer_id' => $locked->customer_id] : []
            );

            return $locked;
        });
    }

    protected function fulfillVoucher(Purchase $purchase): Purchase
    {
        $result = DB::transaction(function () use ($purchase) {
            $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->first();

            if (! in_array($locked->status, ['verified', 'queued'], true) || $locked->voucher_id !== null) {
                return $locked;
            }

            $voucher = Voucher::where('package_id', $locked->package_id)
                ->where('status', 'available')
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->oldest('id')
                ->lockForUpdate()
                ->first();

            if (! $voucher) {
                ActivityLog::record(
                    'purchase.awaiting_inventory',
                    "Purchase {$locked->reference} verified but no voucher available for package #{$locked->package_id}.",
                    $locked->customer_id ? ['customer_id' => $locked->customer_id] : []
                );

                return $locked;
            }

            $voucher->update([
                'status' => 'assigned',
                'assigned_to' => $locked->customer_id,
                'assigned_at' => now(),
                'purchase_id' => $locked->id,
            ]);

            $locked->update([
                'voucher_id' => $voucher->id,
                'status' => 'voucher_assigned',
            ]);

            if ($locked->customer_id) {
                $locked->customer->update([
                    'status' => 'active',
                    'current_purchase_id' => $locked->id,
                ]);
            }

            ActivityLog::record(
                'voucher.assigned',
                "Voucher {$voucher->code} assigned to purchase {$locked->reference}.",
                $locked->customer_id ? ['customer_id' => $locked->customer_id] : []
            );

            return $locked;
        });

        if ($result->status === 'voucher_assigned' && $result->customer_id) {
            SendVoucherPurchaseEmailJob::dispatch($result->id);
            $result->update(['status' => 'completed']);
        }

        return $result;
    }

    public function expirePurchase(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            $purchase->update(['status' => 'expired']);

            if ($purchase->fulfillment_type === 'live' && $purchase->customer_id && $purchase->router_id) {
                $hotspotUser = HotspotUser::where('customer_id', $purchase->customer_id)
                    ->where('router_id', $purchase->router_id)
                    ->first();

                if ($hotspotUser && $hotspotUser->mikrotik_user_id) {
                    $mikrotik = new MikrotikService($purchase->router);
                    $result = $mikrotik->disableHotspotUser($hotspotUser->mikrotik_user_id);

                    if ($result['success']) {
                        $hotspotUser->update(['disabled' => true]);
                    }
                }
            }

            if ($purchase->customer_id) {
                $hasOtherActive = Purchase::where('customer_id', $purchase->customer_id)
                    ->where('id', '!=', $purchase->id)
                    ->active()
                    ->exists();

                if (! $hasOtherActive) {
                    $purchase->customer->update(['status' => 'inactive']);
                }

                $nextQueued = Purchase::where('customer_id', $purchase->customer_id)
                    ->where('router_id', $purchase->router_id)
                    ->where('status', 'queued')
                    ->oldest()
                    ->first();

                if ($nextQueued) {
                    $this->fulfill($nextQueued, forceActivate: true);
                }
            }
        });
    }

    public function suspendPurchase(Purchase $purchase): array
    {
        $purchase->update(['status' => 'suspended']);

        $mikrotikResult = ['success' => false, 'error' => 'Not a live-fulfilled purchase.'];

        if ($purchase->fulfillment_type === 'live' && $purchase->customer_id && $purchase->router_id) {
            $hotspotUser = HotspotUser::where('customer_id', $purchase->customer_id)
                ->where('router_id', $purchase->router_id)
                ->first();

            if ($hotspotUser && $hotspotUser->mikrotik_user_id) {
                $mikrotik = new MikrotikService($purchase->router);
                $mikrotikResult = $mikrotik->disableHotspotUser($hotspotUser->mikrotik_user_id);

                if ($mikrotikResult['success']) {
                    $hotspotUser->update(['disabled' => true]);
                }
            }
        }

        return $mikrotikResult;
    }

    public function activatePurchase(Purchase $purchase): array
    {
        $purchase->update(['status' => $purchase->fulfillment_type === 'live' ? 'active' : 'voucher_assigned']);

        $mikrotikResult = ['success' => false, 'error' => 'Not a live-fulfilled purchase.'];

        if ($purchase->fulfillment_type === 'live' && $purchase->customer_id && $purchase->router_id) {
            $hotspotUser = HotspotUser::where('customer_id', $purchase->customer_id)
                ->where('router_id', $purchase->router_id)
                ->first();

            if ($hotspotUser && $hotspotUser->mikrotik_user_id) {
                $mikrotik = new MikrotikService($purchase->router);
                $mikrotikResult = $mikrotik->enableHotspotUser($hotspotUser->mikrotik_user_id);

                if ($mikrotikResult['success']) {
                    $hotspotUser->update(['disabled' => false]);
                }
            }
        }

        return $mikrotikResult;
    }

    protected function calculateDurationMinutes(Purchase $purchase): int
    {
        $package = $purchase->package;

        return match ($package->duration_unit) {
            'minutes' => $package->duration_value,
            'hours' => $package->duration_value * 60,
            'days' => $package->duration_value * 1440,
            'weeks' => $package->duration_value * 10080,
            'months' => $package->duration_value * 43200,
            default => $package->duration_value * 1440,
        };
    }
}
