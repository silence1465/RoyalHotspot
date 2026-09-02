<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BandwidthLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BandwidthController extends Controller
{
    public function summary()
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $todayTotals = BandwidthLog::where('date', $today)
            ->selectRaw('SUM(bytes_in) as bytes_in, SUM(bytes_out) as bytes_out')
            ->first();

        $monthTotals = BandwidthLog::where('date', '>=', $monthStart)
            ->selectRaw('SUM(bytes_in) as bytes_in, SUM(bytes_out) as bytes_out')
            ->first();

        $todayByCustomer = BandwidthLog::where('date', $today)
            ->selectRaw('customer_id, SUM(bytes_in) as bytes_in, SUM(bytes_out) as bytes_out')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $totalByCustomer = BandwidthLog::with('customer:id,full_name,username')
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
        ]);
    }

    public function history(Request $request)
    {
        $period = $request->query('period', 'day');

        if ($period === 'month') {
            $year = (int) $request->query('year', now()->year);

            $rows = BandwidthLog::whereYear('date', $year)
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
            ->selectRaw('date as bucket, SUM(bytes_in) as bytes_in, SUM(bytes_out) as bytes_out')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json(['period' => 'day', 'entries' => $rows]);
    }
}
