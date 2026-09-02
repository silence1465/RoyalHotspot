<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Router;
use App\Models\SystemSetting;
use App\Models\Voucher;
use App\Models\VoucherImportBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * Feeds the two dashboard charts. Deliberately built from Purchase,
     * not Payment — the existing /admin/reports/revenue endpoint only
     * covers Paystack transactions (the Payment model), which would
     * silently exclude every MoMo purchase from a "revenue trend" chart.
     * Purchase covers both gateways, keyed by verified_at (when money
     * was actually confirmed, not when the record was first created).
     */
    public function chartData(Request $request)
    {
        $days = min(90, max(7, $request->integer('days', 30)));
        $from = now()->subDays($days - 1)->startOfDay();

        // Revenue is earned when payment is verified. A later access-state
        // transition (expired, suspended, queued, etc.) must not erase it.
        $rows = Purchase::whereNotNull('verified_at')
            ->where('verified_at', '>=', $from)
            ->selectRaw('DATE(verified_at) as date, payment_method, SUM(amount) as total')
            ->groupBy('date', 'payment_method')
            ->orderBy('date')
            ->get();

        // Build a complete date series (even zero days) so the chart
        // doesn't show misleading gaps as if no data exists there.
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();
            $series[$date] = ['date' => $date, 'total' => 0, 'paystack' => 0, 'momo' => 0];
        }

        foreach ($rows as $row) {
            if (! isset($series[$row->date])) {
                continue;
            }
            $series[$row->date]['total'] += (float) $row->total;
            $series[$row->date][$row->payment_method] = (float) $row->total;
        }

        return response()->json(['series' => array_values($series)]);
    }

    public function stats()
    {
        $now = Carbon::now();

        $lastHeartbeat = SystemSetting::get('sms_forwarder_last_heartbeat_at');
        $heartbeatThreshold = (int) (SystemSetting::get('sms_heartbeat_threshold_minutes') ?? 5);
        $smsForwarderOnline = $lastHeartbeat
            && Carbon::parse($lastHeartbeat)->greaterThan($now->copy()->subMinutes($heartbeatThreshold));

        return response()->json([
            'sms_forwarder_online' => (bool) $smsForwarderOnline,
            'sms_forwarder_last_seen' => $lastHeartbeat,

            'total_customers' => Customer::count(),
            'active_customers' => Customer::where('status', 'active')->count(),
            'suspended_customers' => Customer::where('status', 'suspended')->count(),

            'total_routers' => Router::count(),
            'online_routers' => Router::where('status', 'online')->count(),
            'live_routers' => Router::where('connection_mode', 'live')->count(),
            'manual_routers' => Router::where('connection_mode', 'manual')->count(),

            'active_purchases' => Purchase::active()->count(),
            'live_active' => Purchase::active()->liveFulfilled()->count(),
            'voucher_active' => Purchase::active()->voucherFulfilled()->count(),
            'pending_activation' => Purchase::where('status', 'pending_activation')->count(),
            'manual_review' => Purchase::where('status', 'manual_review')->count(),

            // Combined across both gateways — sourced from Purchase, not
            // Payment, since Payment only ever covers Paystack and would
            // silently exclude every MoMo confirmation from "revenue".
            'today_revenue' => (float) Purchase::whereNotNull('verified_at')
                ->whereDate('verified_at', $now->toDateString())
                ->sum('amount'),

            'month_revenue' => (float) Purchase::whereNotNull('verified_at')
                ->whereYear('verified_at', $now->year)
                ->whereMonth('verified_at', $now->month)
                ->sum('amount'),

            'payment_methods' => [
                'paystack' => filter_var(SystemSetting::get('paystack_enabled', '1'), FILTER_VALIDATE_BOOLEAN),
                'momo' => filter_var(SystemSetting::get('momo_enabled', '1'), FILTER_VALIDATE_BOOLEAN),
            ],

            'today_bandwidth' => (int) \App\Models\BandwidthLog::where('date', $now->toDateString())
                ->selectRaw('COALESCE(SUM(bytes_in + bytes_out), 0) as total')->value('total'),

            'month_bandwidth' => (int) \App\Models\BandwidthLog::where('date', '>=', $now->copy()->startOfMonth()->toDateString())
                ->selectRaw('COALESCE(SUM(bytes_in + bytes_out), 0) as total')->value('total'),

            'low_stock_package_count' => Voucher::select('package_id')
                ->where('status', 'available')
                ->groupBy('package_id')
                ->havingRaw('COUNT(*) <= ?', [(int) (SystemSetting::get('low_stock_threshold') ?? 10)])
                ->get()
                ->count(),

            'purchases_by_status' => Purchase::selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),

            'recent_purchases' => Purchase::with(['customer:id,full_name', 'package:id,name'])
                ->latest()
                ->take(10)
                ->get(['id', 'customer_id', 'package_id', 'reference', 'amount', 'status', 'payment_method', 'fulfillment_type', 'created_at']),

            'recent_payments' => Payment::with(['customer:id,full_name,username'])
                ->latest()
                ->take(10)
                ->get(['id', 'customer_id', 'reference', 'amount', 'status', 'paid_at', 'created_at']),

            'recent_customers' => Customer::latest()
                ->take(10)
                ->get(['id', 'full_name', 'username', 'phone', 'status', 'created_at']),

            'recent_imports' => VoucherImportBatch::with('uploadedBy:id,name')
                ->latest()
                ->take(5)
                ->get(['id', 'original_filename', 'uploaded_by', 'status', 'total_imported', 'created_at']),
        ]);
    }
}
