<?php

namespace App\Services;

use App\Jobs\ActivateHotspotUserJob;
use App\Jobs\SendVoucherPurchaseEmailJob;
use App\Models\ActivityLog;
use App\Models\HotspotSession;
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
    public function __construct(protected MikrotikServiceFactory $mikrotikFactory) {}

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

        if (! $purchase->router_id) {
            return [
                'success' => false,
                'message' => 'This queued purchase has no hotspot location. Please contact an administrator.',
            ];
        }

        if ($this->hasActiveOnSameRouter($purchase)) {
            return [
                'success' => false,
                'message' => 'You already have an active subscription on this router. It will activate automatically once that one expires.',
            ];
        }

        $activated = $this->fulfill($purchase, forceActivate: true);

        if ($activated->status === 'queued') {
            return [
                'success' => false,
                'message' => 'No voucher is available for this package and location yet. Please contact an administrator.',
            ];
        }

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

            $isFixedTrial = $locked->payment_method === 'free_trial' && $locked->expires_at;
            app(FupService::class)->snapshotPolicy($locked, $locked->package);
            $locked->update([
                'status' => 'active',
                // Live access is provisioned now, but its purchased time
                // begins only when the customer actually connects.
                'starts_at' => $isFixedTrial ? ($locked->starts_at ?? now()) : null,
                'expires_at' => $isFixedTrial ? $locked->expires_at : null,
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

    /**
     * Start a paid live package when the customer is at the hotspot and
     * the router has confirmed it is reachable. Idempotent so repeated
     * Connect taps never reset or extend an existing access window.
     */
    public function activateLiveAccess(Purchase $purchase): Purchase
    {
        if (! $purchase->isLive()) {
            return $purchase;
        }

        return DB::transaction(function () use ($purchase) {
            $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->first();

            if ($locked->status !== 'active' || $locked->starts_at || $locked->expires_at) {
                return $locked;
            }

            $now = now();
            $locked->update([
                'starts_at' => $now,
                'expires_at' => $now->copy()->addMinutes($this->calculateDurationMinutes($locked)),
            ]);

            ActivityLog::record(
                'purchase.access_started',
                "Purchase {$locked->reference} countdown started on first hotspot connection.",
                $locked->customer_id ? ['customer_id' => $locked->customer_id] : []
            );

            return $locked->fresh();
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
                ->where(function ($query) use ($locked) {
                    $query->where('router_id', $locked->router_id)
                        ->orWhereNull('router_id');
                })
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
                'router_id' => $voucher->router_id ?? $locked->router_id,
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

    /**
     * Starts a voucher-fulfilled purchase's calendar access window on
     * first actual use — not at redemption/assignment, which only means
     * the code changed hands. Idempotent: a purchase whose window already
     * started (starts_at set) is left untouched, so repeat "Connect to
     * WiFi" clicks or reconnects don't reset the clock. Live purchases
     * use activateLiveAccess() after the router becomes reachable and
     * are untouched here.
     */
    public function activateVoucherAccess(Purchase $purchase): Purchase
    {
        if ($purchase->fulfillment_type !== 'voucher' || $purchase->voucher_id === null) {
            return $purchase;
        }

        if ($purchase->starts_at !== null) {
            return $purchase;
        }

        $now = now();

        $purchase->update([
            'starts_at' => $now,
            'expires_at' => $now->copy()->addMinutes($this->calculateDurationMinutes($purchase)),
        ]);

        return $purchase->fresh();
    }

    public function expirePurchase(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            $purchase->update(['status' => 'expired']);

            HotspotSession::where('purchase_id', $purchase->id)
                ->whereIn('status', ['connecting', 'active'])
                ->update([
                    'status' => 'expired',
                    'disconnect_reason' => 'purchase_expired',
                    'ended_at' => now(),
                ]);

            if ($purchase->fulfillment_type === 'live' && $purchase->router_id) {
                $hotspotUser = $purchase->customer_id
                    ? HotspotUser::where('customer_id', $purchase->customer_id)
                        ->where('router_id', $purchase->router_id)
                        ->first()
                    : null;

                // A guest's generated code is its RouterOS username. Guests
                // have no HotspotUser row, so the old customer-only branch
                // marked their purchase expired without cutting off access.
                $username = $purchase->isGuest()
                    ? $purchase->guest_code
                    : $hotspotUser?->username;

                if ($username) {
                    $mikrotik = $this->mikrotikFactory->make($purchase->router);
                    $result = $mikrotik->disableAndDisconnectHotspotUser(
                        $username,
                        $hotspotUser?->mikrotik_user_id
                    );

                    if (! $result['success']) {
                        throw new \RuntimeException('Could not revoke expired hotspot access: '.($result['error'] ?? 'unknown error'));
                    }

                    if ($hotspotUser) {
                        $hotspotUser->update(['disabled' => true]);
                    }
                }
            }

            // Voucher-fulfilled purchases never got here at all before —
            // ExpirePurchases only ever queried fulfillment_type='live'.
            // The RouterOS account behind a voucher code (created live at
            // Admin\VoucherController::generate() time) would just keep
            // working forever past its paid duration. mikrotik_user_id is
            // only populated for vouchers generated after that field was
            // added — an older/PDF-imported voucher has no live account to
            // disable, but its DB status still gets marked expired so it
            // stops showing as usable.
            if ($purchase->fulfillment_type === 'voucher' && $purchase->voucher_id) {
                $voucher = $purchase->voucher;

                if ($voucher && $voucher->mikrotik_user_id && $purchase->router_id) {
                    $mikrotik = $this->mikrotikFactory->make($purchase->router);
                    $result = $mikrotik->disableAndDisconnectHotspotUser(
                        $voucher->code,
                        $voucher->mikrotik_user_id
                    );

                    if (! $result['success']) {
                        throw new \RuntimeException('Could not revoke expired voucher access: '.($result['error'] ?? 'unknown error'));
                    }

                    $voucher->update(['status' => 'expired']);
                } elseif ($voucher) {
                    $voucher->update(['status' => 'expired']);
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

        $baseMinutes = match ($package->duration_unit) {
            'minutes' => $package->duration_value,
            'hours' => $package->duration_value * 60,
            'days' => $package->duration_value * 1440,
            'weeks' => $package->duration_value * 10080,
            'months' => $package->duration_value * 43200,
            default => $package->duration_value * 1440,
        };

        return $baseMinutes + ($purchase->payment_method === 'momo' ? (int) $purchase->bonus_duration_minutes : 0);
    }
}
