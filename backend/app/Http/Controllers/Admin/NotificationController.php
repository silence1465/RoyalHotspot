<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\FreeTrialCampaign;
use App\Models\HotspotSession;
use App\Models\InternetPackage;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Router;
use App\Models\SystemSetting;
use App\Models\Voucher;
use Illuminate\Http\Request;

/**
 * Backs the admin notification bell — badge counts are live-computed
 * (recalculated fresh on every request, not stored/tracked as
 * read/unread) and the feed reuses the existing activity_logs table
 * rather than a new notifications table, since every meaningful event
 * in the system already writes there via ActivityLog::record().
 */
class NotificationController extends Controller
{
    public function summary(Request $request)
    {
        // Global activity messages may contain another router's customer data.
        if (! $request->user()->isSuperAdmin() || $request->attributes->get('admin_router_ids') !== null) {
            return response()->json(['badges' => [], 'navigation_counts' => [], 'recent' => []]);
        }

        return response()->json([
            'badges' => [
                // 'Needs Attention' on the Purchases page — manual_review
                // (ambiguous SMS match) and pending_activation (MikroTik
                // call failed) are the two states that need a human.
                'purchases' => Purchase::whereIn('status', ['manual_review', 'pending_activation'])->count(),
                'complaints' => Complaint::where('status', 'open')->count(),
                'routers_offline' => Router::where('connection_mode', 'live')->where('status', 'offline')->count(),
            ],
            'navigation_counts' => [
                '/admin/dashboard' => Purchase::active()->count(),
                '/admin/router-management' => Router::where('connection_mode', 'live')->where('status', 'online')->count(),
                '/admin/routers' => Router::where('status', 'online')->count(),
                '/admin/customers' => Customer::where('status', 'active')->count(),
                '/admin/packages' => InternetPackage::active()->count(),
                '/admin/bandwidth' => HotspotSession::whereNull('ended_at')->count(),
                '/admin/active-sessions' => HotspotSession::whereNull('ended_at')->count(),
                '/admin/purchases' => Purchase::active()->count(),
                '/admin/payments' => Payment::successful()->count(),
                '/admin/accounting' => Payment::successful()->count(),
                '/admin/vouchers' => Voucher::where('status', 'available')->count(),
                '/admin/assign-package' => Purchase::whereIn('status', ['verified', 'queued', 'pending_activation'])->count(),
                '/admin/free-trials' => FreeTrialCampaign::where('is_active', true)
                    ->where('starts_at', '<=', now())->where('ends_at', '>=', now())->count(),
                '/admin/complaints' => Complaint::where('status', 'open')->count(),
                '/admin/logs' => ActivityLog::count(),
                '/admin/settings' => SystemSetting::count(),
            ],
            'recent' => ActivityLog::with('user:id,name')
                ->latest()
                ->take(15)
                ->get(['id', 'user_id', 'action', 'description', 'created_at']),
        ]);
    }
}
