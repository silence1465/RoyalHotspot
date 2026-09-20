<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\FreeTrialCampaign;
use App\Models\Purchase;
use App\Services\PurchaseService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FreeTrialController extends Controller
{
    public function offer(Request $request)
    {
        $customer = $request->user();
        if (! $customer->home_router_id) {
            return response()->json(['campaign' => null]);
        }

        $campaign = FreeTrialCampaign::with(['package', 'router:id,name,location,status,connection_mode'])
            ->where('router_id', $customer->home_router_id)
            ->where('is_active', true)->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->orderBy('ends_at')->first();

        if (! $campaign) {
            return response()->json(['campaign' => null]);
        }

        $claimed = Purchase::where('free_trial_campaign_id', $campaign->id)
            ->where('customer_id', $customer->id)->exists();
        $hasActiveAccess = $this->hasActiveAccess($customer->id, $campaign->router_id);

        return response()->json([
            'campaign' => $campaign,
            'claimed' => $claimed,
            'can_claim' => ! $claimed && ! $hasActiveAccess,
            'claim_unavailable_reason' => $hasActiveAccess ? 'Available after your current package ends.' : null,
        ]);
    }

    public function claim(Request $request, PurchaseService $purchaseService)
    {
        $request->validate(['campaign_id' => ['required', 'integer', 'exists:free_trial_campaigns,id']]);

        try {
            $purchase = DB::transaction(function () use ($request) {
                $campaign = FreeTrialCampaign::whereKey($request->integer('campaign_id'))->lockForUpdate()->firstOrFail();
                abort_unless($campaign->isOpen(), 422, 'This free-access campaign is not currently available.');
                abort_unless((int) $campaign->router_id === (int) $request->user()->home_router_id, 422, 'This free campaign is not available at your assigned router.');
                abort_if($campaign->router->connection_mode !== 'live', 422, 'The campaign router is not configured for automatic access.');

                $hasActive = $this->hasActiveAccess($request->user()->id, $campaign->router_id);
                abort_if($hasActive, 422, 'You already have active internet at this location.');

                return Purchase::create([
                    'customer_id' => $request->user()->id,
                    'free_trial_campaign_id' => $campaign->id,
                    'package_id' => $campaign->package_id,
                    'router_id' => $campaign->router_id,
                    'subtotal' => 0,
                    'payment_fee' => 0,
                    'amount' => 0,
                    'reference' => Purchase::generateReference(),
                    'payment_method' => 'free_trial',
                    'fulfillment_type' => 'live',
                    'status' => 'verified',
                    'verified_at' => now(),
                    'starts_at' => now(),
                    'expires_at' => $campaign->ends_at,
                ]);
            });
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                return response()->json(['message' => 'You have already claimed this free campaign.'], 422);
            }
            throw $e;
        }

        $purchase = $purchaseService->fulfill($purchase);

        return response()->json(['message' => 'Free internet activated.', 'purchase' => $purchase], 201);
    }

    private function hasActiveAccess(int $customerId, int $routerId): bool
    {
        return Purchase::where('customer_id', $customerId)
            ->where('router_id', $routerId)
            ->where(function ($query) {
                $query->active()->orWhere('status', 'pending_activation');
            })
            ->exists();
    }
}
