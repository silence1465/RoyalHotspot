<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\HotspotUser;
use App\Models\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SubscriptionService
 *
 * Owns subscription lifecycle: activation, expiry calculation, and the
 * decision of when to actually disable a MikroTik hotspot user relative
 * to the configured grace period.
 *
 * IMPORTANT: activateSubscription() MUST be idempotent. It's called from
 * both the Paystack webhook and the verify-on-redirect path, and either
 * one can fire more than once for the same payment (see review note on
 * webhook idempotency). Always check current status before mutating.
 */
class SubscriptionService
{
    /**
     * Activate a subscription after a payment has been confirmed successful.
     * Safe to call multiple times for the same subscription — a no-op if
     * it's already active. MikroTik user creation is dispatched as a queued
     * job, not called synchronously, so this method (and the webhook
     * handler that calls it) returns fast.
     */
    public function activateSubscription(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $subscription->refresh();
            $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->first();

            if ($locked->status === 'active') {
                // Already activated by a concurrent/duplicate call — no-op.
                return $locked;
            }

            $startsAt = Carbon::now();
            $expiresAt = $this->calculateExpiry($startsAt, $locked->package);

            $locked->update([
                'status'     => 'active',
                'starts_at'  => $startsAt,
                'expires_at' => $expiresAt,
            ]);

            // Customer becomes 'active' and points at this subscription as
            // soon as it activates — this belongs in the activation logic
            // itself, not scattered into the webhook controller that calls
            // it, so every activation path (webhook, admin retry, future
            // voucher redemption in Phase 11) gets this consistently.
            $locked->customer->update([
                'status' => 'active',
                'current_subscription_id' => $locked->id,
            ]);

            // Queued, not synchronous — see class docblock.
            \App\Jobs\ActivateHotspotUserJob::dispatch($locked->id);

            return $locked->fresh();
        });
    }

    public function calculateExpiry(Carbon $startsAt, $package): Carbon
    {
        return match ($package->duration_unit) {
            'minutes' => $startsAt->copy()->addMinutes($package->duration_value),
            'hours'   => $startsAt->copy()->addHours($package->duration_value),
            'days'    => $startsAt->copy()->addDays($package->duration_value),
            'weeks'   => $startsAt->copy()->addWeeks($package->duration_value),
            'months'  => $startsAt->copy()->addMonths($package->duration_value),
            default   => throw new \InvalidArgumentException("Unknown duration_unit: {$package->duration_unit}"),
        };
    }

    /**
     * Create (or re-enable, if a hotspot_users row already exists for this
     * customer+router) the MikroTik hotspot user. Called from
     * ActivateHotspotUserJob, not directly from the webhook.
     *
     * The hotspot password generated here is a random, hotspot-only secret
     * — never the customer's account login password (see review note on
     * dropping customers.password_text).
     */
    public function createOrEnableHotspotUser(Subscription $subscription): array
    {
        $customer = $subscription->customer;
        $router = $subscription->router;

        $mikrotik = new \App\Services\MikrotikService($router);

        $existing = HotspotUser::where('customer_id', $customer->id)
            ->where('router_id', $router->id)
            ->first();

        if ($existing && $existing->mikrotik_user_id) {
            $result = $mikrotik->enableHotspotUser($existing->mikrotik_user_id);

            if ($result['success']) {
                $existing->update([
                    'disabled' => false,
                    'profile' => $router->profileNameFor($subscription->package),
                ]);
            }

            return $result;
        }

        $username = $customer->username;
        $password = Str::random(10);
        // Profile names are per-router (see router_package_profiles pivot)
        // — internet_packages no longer has a global mikrotik_profile column.
        $profile = $router->profileNameFor($subscription->package);

        $result = $mikrotik->createHotspotUser($username, $password, $profile);

        if ($result['success']) {
            HotspotUser::updateOrCreate(
                ['customer_id' => $customer->id, 'router_id' => $router->id],
                [
                    'mikrotik_user_id' => $result['data'][0]['.id'] ?? null,
                    'username'         => $username,
                    'password'         => $password,
                    'profile'          => $profile,
                    'disabled'         => false,
                ]
            );
        }

        return $result;
    }

    /**
     * Whether `now` still falls within the configured grace period after
     * expires_at. During the grace period the subscription is expired for
     * billing purposes but the MikroTik user is NOT yet disabled — confirm
     * this matches your intended product behavior before relying on it
     * (see review note: grace period was a dangling setting with no defined
     * semantics in the original spec).
     */
    public function isWithinGracePeriod(Subscription $subscription): bool
    {
        $graceMinutes = (int) (\App\Models\SystemSetting::get('grace_period_minutes')
            ?? env('DEFAULT_GRACE_PERIOD_MINUTES', 0));

        if ($graceMinutes <= 0) {
            return false;
        }

        return Carbon::now()->lt($subscription->expires_at->copy()->addMinutes($graceMinutes));
    }

    public function expireSubscription(Subscription $subscription): void
    {
        DB::transaction(function () use ($subscription) {
            $subscription->update(['status' => 'expired']);

            $hotspotUser = HotspotUser::where('customer_id', $subscription->customer_id)
                ->where('router_id', $subscription->router_id)
                ->first();

            if ($hotspotUser && $hotspotUser->mikrotik_user_id && ! $this->isWithinGracePeriod($subscription)) {
                $mikrotik = new MikrotikService($subscription->router);
                $result = $mikrotik->disableHotspotUser($hotspotUser->mikrotik_user_id);

                // Only reflect 'disabled' in our own records if the router
                // actually confirmed it — otherwise (offline router, or a
                // router in Manual mode with no live API access at all)
                // this would silently claim something happened that
                // didn't. Every other call site that touches
                // hotspot_user.disabled already follows this same
                // check-success-first pattern; this one didn't, which was
                // a real bug found while adding Manual mode.
                if ($result['success']) {
                    $hotspotUser->update(['disabled' => true]);
                }
            }

            $hasOtherActive = Subscription::where('customer_id', $subscription->customer_id)
                ->where('status', 'active')
                ->exists();

            if (! $hasOtherActive) {
                $subscription->customer->update(['status' => 'inactive']);
            }
        });
    }
}
