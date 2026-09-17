<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MikrotikLog;
use App\Models\PaymentSmsLog;
use Illuminate\Http\Request;

class LogController extends Controller
{
    /**
     * Royal WiFi extension — every SMS the forwarder relayed, matched or
     * not (see docs/DATABASE_SCHEMA.md on why unmatched SMS are kept, not
     * discarded). Admin can inspect the raw SMS content, which record
     * (Order) it matched, and how.
     */
    public function smsLogs(Request $request)
    {
        $query = PaymentSmsLog::with('matchedOrder:id,reference,customer_id');
        abort_unless($request->user()->hasPermission('transactions.momo.view'), 403);
        $ids = $request->attributes->get('admin_router_ids');
        if ($ids !== null) {
            $query->whereHas('matchedPurchase', fn ($purchase) => $purchase->whereIn('router_id', $ids));
        }

        if ($status = $request->query('status')) {
            $query->where('verification_status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('transaction_id', 'like', "%{$search}%")
                    ->orWhere('parsed_reference', 'like', "%{$search}%")
                    ->orWhere('sender', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->latest()->paginate($request->integer('per_page', 10))
        );
    }

    public function mikrotikLogs(Request $request)
    {
        $query = MikrotikLog::with('router:id,name');
        abort_unless($request->user()->hasPermission('logs.view'), 403);
        $ids = $request->attributes->get('admin_router_ids');
        if ($ids !== null) {
            $query->whereIn('router_id', $ids);
        }

        if ($routerId = $request->query('router_id')) {
            $query->where('router_id', $routerId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        // request_payload / response_payload already have sensitive fields
        // redacted at write time (see MikrotikService::logAction) — safe
        // to return as-is.
        return response()->json(
            $query->latest()->paginate($request->integer('per_page', 10))
        );
    }

    public function activityLogs(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Global activity logs require a super administrator.');
        $query = ActivityLog::with(['user:id,name', 'customer:id,full_name,username']);

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($customerId = $request->query('customer_id')) {
            $query->where('customer_id', $customerId);
        }

        if ($action = $request->query('action')) {
            $query->where('action', 'like', "%{$action}%");
        }

        return response()->json(
            $query->latest()->paginate($request->integer('per_page', 10))
        );
    }
}
