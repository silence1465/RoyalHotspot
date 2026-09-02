<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ActivateHotspotUserJob;
use App\Models\ActivityLog;
use App\Models\HotspotUser;
use App\Models\Subscription;
use App\Services\MikrotikService;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function index(Request $request)
    {
        $query = Subscription::with(['customer:id,full_name,username,phone', 'package:id,name', 'router:id,name']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($customerId = $request->query('customer_id')) {
            $query->where('customer_id', $customerId);
        }

        if ($routerId = $request->query('router_id')) {
            $query->where('router_id', $routerId);
        }

        return response()->json(
            $query->latest()->paginate($request->integer('per_page', 10))
        );
    }

    public function show(Subscription $subscription)
    {
        return response()->json(
            $subscription->load(['customer', 'package', 'router:id,name,location', 'payments'])
        );
    }

    /**
     * Manually suspend an active subscription — disables the MikroTik
     * hotspot user immediately but does NOT touch expires_at, so the
     * customer's remaining paid time is preserved for when an admin
     * reactivates them (e.g. a support/abuse hold, not a cancellation).
     */
    public function suspend(Request $request, Subscription $subscription)
    {
        if ($subscription->status !== 'active') {
            return response()->json([
                'message' => "Only an active subscription can be suspended. This one is '{$subscription->status}'.",
            ], 422);
        }

        $hotspotUser = HotspotUser::where('customer_id', $subscription->customer_id)
            ->where('router_id', $subscription->router_id)
            ->first();

        if ($hotspotUser?->mikrotik_user_id) {
            $mikrotik = new MikrotikService($subscription->router);
            $result = $mikrotik->disableHotspotUser($hotspotUser->mikrotik_user_id);

            if ($result['success']) {
                $hotspotUser->update(['disabled' => true]);
            }
        }

        $subscription->update(['status' => 'suspended']);

        ActivityLog::record(
            'subscription.suspended',
            "Admin suspended subscription #{$subscription->id}.",
            ['user_id' => $request->user()->id]
        );

        return response()->json($subscription->fresh());
    }

    /**
     * Un-suspend a subscription — re-enables the MikroTik hotspot user.
     * Deliberately does NOT recompute expires_at (that's only ever set by
     * SubscriptionService::activateSubscription() on first activation) —
     * this just restores access for whatever time was already remaining
     * when it was suspended.
     */
    public function activate(Request $request, Subscription $subscription)
    {
        if ($subscription->status !== 'suspended') {
            return response()->json([
                'message' => "Only a suspended subscription can be reactivated this way. This one is '{$subscription->status}'.",
            ], 422);
        }

        if ($subscription->expires_at && $subscription->expires_at->isPast()) {
            return response()->json([
                'message' => 'This subscription already expired while suspended — the customer needs a new subscription instead.',
            ], 422);
        }

        $hotspotUser = HotspotUser::where('customer_id', $subscription->customer_id)
            ->where('router_id', $subscription->router_id)
            ->first();

        if ($hotspotUser?->mikrotik_user_id) {
            $mikrotik = new MikrotikService($subscription->router);
            $result = $mikrotik->enableHotspotUser($hotspotUser->mikrotik_user_id);

            if ($result['success']) {
                $hotspotUser->update(['disabled' => false]);
            }
        }

        $subscription->update(['status' => 'active']);
        $subscription->customer->update(['status' => 'active', 'current_subscription_id' => $subscription->id]);

        ActivityLog::record(
            'subscription.reactivated',
            "Admin reactivated subscription #{$subscription->id}.",
            ['user_id' => $request->user()->id]
        );

        return response()->json($subscription->fresh());
    }

    /**
     * Cancel a subscription outright — unlike suspend, this is terminal;
     * a cancelled subscription can't later be reactivated via activate().
     */
    public function cancel(Request $request, Subscription $subscription)
    {
        if (in_array($subscription->status, ['expired', 'cancelled'])) {
            return response()->json([
                'message' => "Subscription is already '{$subscription->status}'.",
            ], 422);
        }

        $hotspotUser = HotspotUser::where('customer_id', $subscription->customer_id)
            ->where('router_id', $subscription->router_id)
            ->first();

        if ($hotspotUser?->mikrotik_user_id && ! $hotspotUser->disabled) {
            $mikrotik = new MikrotikService($subscription->router);
            $result = $mikrotik->disableHotspotUser($hotspotUser->mikrotik_user_id);

            if ($result['success']) {
                $hotspotUser->update(['disabled' => true]);
            }
        }

        $subscription->update(['status' => 'cancelled']);

        ActivityLog::record(
            'subscription.cancelled',
            "Admin cancelled subscription #{$subscription->id}.",
            ['user_id' => $request->user()->id]
        );

        return response()->json($subscription->fresh());
    }

    /**
     * Re-dispatch the MikroTik provisioning job for a subscription stuck
     * in pending_activation (payment succeeded, but the queued job
     * exhausted its retries — see ActivateHotspotUserJob::failed()).
     *
     * Deliberately does NOT call SubscriptionService::activateSubscription()
     * again — that would recompute starts_at/expires_at from now(),
     * silently extending the customer's paid-for duration a second time.
     * The billing period was already fixed when the webhook first
     * activated it; only the MikroTik provisioning step failed.
     */
    public function retryActivation(Request $request, Subscription $subscription)
    {
        if ($subscription->status !== 'pending_activation') {
            return response()->json([
                'message' => "Only subscriptions in 'pending_activation' can be retried. This one is '{$subscription->status}'.",
            ], 422);
        }

        $subscription->update(['status' => 'active']);
        ActivateHotspotUserJob::dispatch($subscription->id);

        ActivityLog::record(
            'subscription.retry_activation',
            "Admin retried MikroTik activation for subscription #{$subscription->id}.",
            ['user_id' => $request->user()->id]
        );

        return response()->json([
            'message' => 'Retry queued.',
            'subscription' => $subscription->fresh(),
        ]);
    }
}
