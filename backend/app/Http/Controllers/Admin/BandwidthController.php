<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ApplyUsagePolicyJob;
use App\Models\ActivityLog;
use App\Models\BandwidthLog;
use App\Models\MonthlyCapacityAdjustment;
use App\Models\Purchase;
use App\Services\FupService;
use App\Support\AdminRouterScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BandwidthController extends Controller
{
    public function summary(Request $request, FupService $fup)
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $routerIds = AdminRouterScope::ids($request);
        $todayTotals = BandwidthLog::where('date', $today)
            ->when($routerIds !== null, fn ($q) => $q->whereIn('router_id', $routerIds))
            ->selectRaw('SUM(bytes_in) as bytes_in, SUM(bytes_out) as bytes_out')
            ->first();

        $monthTotals = BandwidthLog::where('date', '>=', $monthStart)
            ->when($routerIds !== null, fn ($q) => $q->whereIn('router_id', $routerIds))
            ->selectRaw('SUM(bytes_in) as bytes_in, SUM(bytes_out) as bytes_out')
            ->first();

        $todayByCustomer = BandwidthLog::where('date', $today)
            ->when($routerIds !== null, fn ($q) => $q->whereIn('router_id', $routerIds))
            ->selectRaw('customer_id, SUM(bytes_in) as bytes_in, SUM(bytes_out) as bytes_out')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $totalByCustomer = BandwidthLog::with('customer:id,full_name,username')
            ->when($routerIds !== null, fn ($q) => $q->whereIn('router_id', $routerIds))
            ->selectRaw('customer_id, SUM(bytes_in) as bytes_in, SUM(bytes_out) as bytes_out')
            ->groupBy('customer_id')
            ->orderByRaw('SUM(bytes_in) + SUM(bytes_out) DESC')
            ->get();

        $users = $totalByCustomer->map(function ($row) use ($todayByCustomer) {
            $today = $todayByCustomer->get($row->customer_id);

            return [
                'customer_id' => $row->customer_id,
                'name' => $row->customer->full_name ?? 'Unknown',
                'username' => $row->customer->username ?? null,
                'today_bytes_in' => (int) ($today->bytes_in ?? 0),
                'today_bytes_out' => (int) ($today->bytes_out ?? 0),
                'total_bytes_in' => (int) $row->bytes_in,
                'total_bytes_out' => (int) $row->bytes_out,
            ];
        });

        $capacity = MonthlyCapacityAdjustment::currentForMonth(now());
        $monthUsed = (int) ($monthTotals->bytes_in ?? 0) + (int) ($monthTotals->bytes_out ?? 0);
        $capacitySummary = null;
        if ($capacity) {
            $usable = (int) floor($capacity->capacity_bytes * (100 - $capacity->reserve_percent) / 100);
            $remaining = max(0, $usable - $monthUsed);
            $remainingDays = now()->daysInMonth - now()->day + 1;
            $capacitySummary = [
                'capacity_bytes' => $capacity->capacity_bytes,
                'reserve_percent' => $capacity->reserve_percent,
                'usable_bytes' => $usable,
                'remaining_bytes' => $remaining,
                'daily_target_bytes' => $remainingDays > 0 ? (int) floor($remaining / $remainingDays) : 0,
                'projected_month_end_bytes' => now()->day > 0 ? (int) round($monthUsed / now()->day * now()->daysInMonth) : 0,
                'control' => $fup->monthlyControl(),
            ];
        }

        return response()->json([
            'today_total' => [
                'bytes_in' => (int) ($todayTotals->bytes_in ?? 0),
                'bytes_out' => (int) ($todayTotals->bytes_out ?? 0),
            ],
            'month_total' => [
                'bytes_in' => (int) ($monthTotals->bytes_in ?? 0),
                'bytes_out' => (int) ($monthTotals->bytes_out ?? 0),
            ],
            'users' => $users,
            'capacity' => $capacitySummary,
            'capacity_history' => MonthlyCapacityAdjustment::with('administrator:id,name')
                ->whereDate('month', now()->startOfMonth()->toDateString())
                ->latest('id')
                ->get(),
        ]);
    }

    public function updateCapacity(Request $request)
    {
        $data = $request->validate([
            'capacity_gb' => ['required', 'numeric', 'min:0.001', 'max:1048576'],
            'reserve_percent' => ['required', 'integer', 'min:0', 'max:90'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $previous = MonthlyCapacityAdjustment::currentForMonth(now());
        $adjustment = MonthlyCapacityAdjustment::create([
            'month' => now()->startOfMonth()->toDateString(),
            'capacity_bytes' => (int) round($data['capacity_gb'] * 1024 * 1024 * 1024),
            'reserve_percent' => $data['reserve_percent'],
            'reason' => $data['reason'],
            'adjusted_by' => $request->user()->id,
        ]);

        ActivityLog::record('bandwidth.capacity_adjusted', 'Monthly bandwidth capacity adjusted.', [
            'user_id' => $request->user()->id,
            'old_capacity_bytes' => $previous?->capacity_bytes,
            'new_capacity_bytes' => $adjustment->capacity_bytes,
        ]);

        Purchase::where('status', 'active')
            ->where('usage_policy', 'fup')
            ->pluck('id')
            ->each(fn ($id) => ApplyUsagePolicyJob::dispatch($id));

        return response()->json($adjustment, 201);
    }

    public function history(Request $request)
    {
        $period = $request->query('period', 'day');

        if ($period === 'month') {
            $year = (int) $request->query('year', now()->year);

            $rows = BandwidthLog::whereYear('date', $year)
                ->when(AdminRouterScope::ids($request) !== null, fn ($q) => $q->whereIn('router_id', AdminRouterScope::ids($request)))
                ->selectRaw("DATE_FORMAT(date, '%Y-%m') as bucket, SUM(bytes_in) as bytes_in, SUM(bytes_out) as bytes_out")
                ->groupBy('bucket')
                ->orderBy('bucket')
                ->get();

            return response()->json(['period' => 'month', 'entries' => $rows]);
        }

        $month = $request->query('month', now()->format('Y-m'));
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $rows = BandwidthLog::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when(AdminRouterScope::ids($request) !== null, fn ($q) => $q->whereIn('router_id', AdminRouterScope::ids($request)))
            ->selectRaw('date as bucket, SUM(bytes_in) as bytes_in, SUM(bytes_out) as bytes_out')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json(['period' => 'day', 'entries' => $rows]);
    }
}
