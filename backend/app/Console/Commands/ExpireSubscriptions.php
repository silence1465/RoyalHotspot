<?php

namespace App\Console\Commands;

use App\Models\HotspotUser;
use App\Models\Subscription;
use App\Services\MikrotikService;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Expire subscriptions past their expires_at and disable hotspot users whose grace period has elapsed';

    public function handle(SubscriptionService $subscriptionService): int
    {
        $expiredCount = 0;

        Subscription::where('status', 'active')
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($subscriptions) use ($subscriptionService, &$expiredCount) {
                foreach ($subscriptions as $subscription) {
                    $subscriptionService->expireSubscription($subscription);
                    $expiredCount++;
                }
            });

        $this->info("Expired {$expiredCount} subscription(s).");

        // ── Grace-period sweep ──────────────────────────────────────
        // expireSubscription() flips status to 'expired' immediately at
        // expires_at, but — per the grace-period semantics documented in
        // SubscriptionService::isWithinGracePeriod() — only disables the
        // MikroTik hotspot user once the grace period has ALSO elapsed.
        // Once status is 'expired' it's no longer matched by the query
        // above, so nothing would ever come back and disable it once the
        // grace period passes. This second sweep is that missing step:
        // it looks at every still-enabled hotspot user, finds its most
        // recent subscription on that router, and disables it once
        // isWithinGracePeriod() finally returns false.
        //
        // NOTE: this does an N+1-style lookup per hotspot user rather than
        // a single join, because hotspot_users isn't linked to a specific
        // subscription_id (it's keyed by customer_id + router_id, since a
        // hotspot login is reused across subscriptions on the same
        // router — see docs/DATABASE_SCHEMA.md). Fine at small-ISP scale;
        // if this table grows very large, consider adding
        // hotspot_users.last_subscription_id to make this a single query.
        $disabledCount = 0;

        HotspotUser::where('disabled', false)
            ->whereNotNull('mikrotik_user_id')
            ->with('router')
            ->chunkById(100, function ($hotspotUsers) use ($subscriptionService, &$disabledCount) {
                foreach ($hotspotUsers as $hotspotUser) {
                    $subscription = Subscription::where('customer_id', $hotspotUser->customer_id)
                        ->where('router_id', $hotspotUser->router_id)
                        ->where('status', 'expired')
                        ->latest('expires_at')
                        ->first();

                    if (! $subscription || $subscriptionService->isWithinGracePeriod($subscription)) {
                        continue;
                    }

                    $mikrotik = new MikrotikService($hotspotUser->router);
                    $result = $mikrotik->disableHotspotUser($hotspotUser->mikrotik_user_id);

                    if ($result['success']) {
                        $hotspotUser->update(['disabled' => true]);
                        $disabledCount++;
                    }
                }
            });

        if ($disabledCount > 0) {
            $this->info("Disabled {$disabledCount} hotspot user(s) past their grace period.");
        }

        return self::SUCCESS;
    }
}
