<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Router;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function accounting(Request $request)
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'router_id' => ['nullable', 'integer', 'exists:routers,id'],
            'payment_method' => ['nullable', 'in:paystack,momo,admin_grant,free_trial'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $year = (int) ($validated['year'] ?? now()->year);
        $query = Purchase::query()
            ->whereNotNull('verified_at')
            ->whereYear('verified_at', $year);

        if (isset($validated['month'])) {
            $query->whereMonth('verified_at', $validated['month']);
        }
        if (isset($validated['router_id'])) {
            $query->where('router_id', $validated['router_id']);
        }
        if (isset($validated['payment_method'])) {
            $query->where('payment_method', $validated['payment_method']);
        }

        $summary = (clone $query)
            ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(subtotal), 0) as subtotal, COALESCE(SUM(payment_fee), 0) as fees, COALESCE(SUM(amount), 0) as total')
            ->first();

        $byMethod = (clone $query)
            ->selectRaw('payment_method, COUNT(*) as transaction_count, COALESCE(SUM(amount), 0) as total')
            ->groupBy('payment_method')
            ->orderBy('payment_method')
            ->get();

        $entries = (clone $query)
            ->with([
                'customer:id,full_name,username',
                'package:id,name',
                'router' => fn ($routerQuery) => $routerQuery->withTrashed()->select('id', 'name', 'location'),
            ])
            ->latest('verified_at')
            ->paginate($validated['per_page'] ?? 10);

        $years = Purchase::whereNotNull('verified_at')
            ->pluck('verified_at')
            ->map(fn ($date) => $date->year)
            ->unique()
            ->sortDesc()
            ->values();

        return response()->json([
            'filters' => [
                'year' => $year,
                'month' => $validated['month'] ?? null,
                'router_id' => $validated['router_id'] ?? null,
                'payment_method' => $validated['payment_method'] ?? null,
            ],
            'summary' => [
                'transaction_count' => (int) $summary->transaction_count,
                'subtotal' => (float) $summary->subtotal,
                'fees' => (float) $summary->fees,
                'total' => (float) $summary->total,
            ],
            'by_method' => $byMethod,
            'years' => $years->isEmpty() ? [now()->year] : $years,
            'routers' => Router::withTrashed()->orderBy('name')->get(['id', 'name', 'location', 'deleted_at']),
            'entries' => $entries,
        ]);
    }

    public function revenue(Request $request)
    {
        $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : now()->subDays(29)->startOfDay();
        $to = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : now()->endOfDay();

        $daily = Payment::successful()
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('DATE(paid_at) as date, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_revenue' => (float) $daily->sum('total'),
            'total_payments' => (int) $daily->sum('count'),
            'daily' => $daily,
        ]);
    }

    public function payments(Request $request)
    {
        $query = Payment::with('customer:id,full_name,username');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($provider = $request->query('provider')) {
            $query->where('provider', $provider);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        if ($request->query('export') === 'csv') {
            return $this->streamCsv(
                $query->latest()->get(),
                ['Reference', 'Customer', 'Amount', 'Currency', 'Status', 'Provider', 'Paid At'],
                fn ($p) => [
                    $p->reference,
                    $p->customer?->full_name ?? '—',
                    $p->amount,
                    $p->currency,
                    $p->status,
                    $p->provider,
                    optional($p->paid_at)->toDateTimeString() ?? '',
                ],
                'payments.csv'
            );
        }

        return response()->json($query->latest()->paginate($request->integer('per_page', 10)));
    }

    public function customers(Request $request)
    {
        $query = Customer::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($request->query('export') === 'csv') {
            return $this->streamCsv(
                $query->latest()->get(),
                ['Full Name', 'Phone', 'Username', 'Email', 'Status', 'Registered'],
                fn ($c) => [$c->full_name, $c->phone, $c->username, $c->email ?? '', $c->status, $c->created_at->toDateTimeString()],
                'customers.csv'
            );
        }

        if ($request->query('export') === 'pdf') {
            $customers = $query->latest()->get();

            return \Barryvdh\DomPDF\Facade\Pdf::loadView('admin.customers-pdf', ['customers' => $customers])
                ->setPaper('a4', 'portrait')
                ->download('customers.pdf');
        }

        return response()->json([
            'counts' => [
                'total' => (clone $query)->count(),
                'active' => (clone $query)->where('status', 'active')->count(),
                'inactive' => (clone $query)->where('status', 'inactive')->count(),
                'suspended' => (clone $query)->where('status', 'suspended')->count(),
            ],
            'customers' => $query->latest()->paginate($request->integer('per_page', 10)),
        ]);
    }

    /**
     * Router health/usage snapshot from stored data only — deliberately
     * does NOT make a live RouterOS call per router (that would make this
     * report only as fast as the slowest/most unreachable router). Use
     * the Routers page's "Test Connection" for live status; this is
     * historical/aggregate.
     */
    public function routerActivity(Request $request)
    {
        $routers = Router::withCount([
            'mikrotikLogs as total_actions',
            'mikrotikLogs as failed_actions' => fn ($q) => $q->where('status', 'failed'),
            'hotspotUsers as connected_customers' => fn ($q) => $q->where('disabled', false),
            'subscriptions as active_subscriptions' => fn ($q) => $q->where('status', 'active'),
        ])->get(['id', 'name', 'location', 'status']);

        return response()->json($routers);
    }

    /**
     * Streams a CSV directly rather than building the whole file in
     * memory — matters once a report covers thousands of rows.
     */
    protected function streamCsv(iterable $rows, array $headers, callable $mapRow, string $filename)
    {
        return response()->streamDownload(function () use ($rows, $headers, $mapRow) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $mapRow($row));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
