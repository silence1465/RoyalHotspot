<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\BandwidthLog;
use App\Models\HotspotUser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $customer = $request->user();

        $purchase = $customer->purchases()
            ->with(['package:id,name,duration_value,duration_unit,speed_limit,data_limit', 'router:id,name', 'voucher:id,code,duration_days'])
            ->where(function ($query) {
                $query->whereIn('status', ['active', 'voucher_assigned', 'completed', 'pending_activation'])
                    ->orWhere(function ($exhausted) {
                        $exhausted->where('status', 'expired')
                            ->where('policy_access_status', 'data_exhausted');
                    });
            })
            ->latest()
            ->first();

        $hotspotCredentials = null;

        if ($purchase && $purchase->isLive() && $purchase->router_id) {
            $hotspotUser = HotspotUser::where('customer_id', $customer->id)
                ->where('router_id', $purchase->router_id)
                ->first();

            if ($hotspotUser && $hotspotUser->mikrotik_user_id && ! $hotspotUser->disabled
                && $purchase->status !== 'pending_activation') {
                $hotspotCredentials = [
                    'username' => $hotspotUser->username,
                    'password' => $hotspotUser->makeVisible('password')->password,
                    'disabled' => false,
                ];
            }
        }

        $remainingSeconds = null;
        if ($purchase?->expires_at) {
            $remainingSeconds = max(0, Carbon::now()->diffInSeconds($purchase->expires_at, false));
        }

        $bandwidthToday = BandwidthLog::where('customer_id', $customer->id)
            ->where('date', now()->toDateString())
            ->selectRaw('COALESCE(SUM(bytes_in + bytes_out), 0) as total')
            ->value('total');

        $bandwidthTotal = BandwidthLog::where('customer_id', $customer->id)
            ->selectRaw('COALESCE(SUM(bytes_in + bytes_out), 0) as total')
            ->value('total');

        return response()->json([
            'customer' => [
                'full_name' => $customer->full_name,
                'username' => $customer->username,
                'status' => $customer->status,
            ],
            'purchase' => $purchase,
            'remaining_seconds' => $remainingSeconds,
            'hotspot_credentials' => $hotspotCredentials,
            'bandwidth_today' => (int) $bandwidthToday,
            'bandwidth_total' => (int) $bandwidthTotal,
            'voucher' => ($purchase && $purchase->isVoucher() && $purchase->voucher) ? [
                'code' => $purchase->voucher->code,
                'duration_days' => $purchase->voucher->duration_days,
            ] : null,
            // Purchase is the unified payment record for Paystack, MoMo,
            // vouchers, and admin grants. The old Payment relation contains
            // Paystack rows only and made MoMo history appear empty.
            'recent_payments' => $customer->purchases()
                ->whereNotNull('verified_at')
                ->latest('verified_at')
                ->take(5)
                ->get(['id', 'reference', 'amount', 'status', 'payment_method', 'verified_at', 'created_at'])
                ->map(fn ($purchase) => [
                    'id' => $purchase->id,
                    'reference' => $purchase->reference,
                    'amount' => $purchase->amount,
                    'currency' => 'GHS',
                    'status' => $purchase->status,
                    'provider' => $purchase->payment_method,
                    'paid_at' => $purchase->verified_at,
                    'created_at' => $purchase->created_at,
                ]),
        ]);
    }
}
