<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Complaint;
use App\Models\Purchase;
use App\Models\Router;

/**
 * Backs the admin notification bell — badge counts are live-computed
 * (recalculated fresh on every request, not stored/tracked as
 * read/unread) and the feed reuses the existing activity_logs table
 * rather than a new notifications table, since every meaningful event
 * in the system already writes there via ActivityLog::record().
 */
class NotificationController extends Controller
{
    public function summary()
    {
        return response()->json([
            'badges' => [
                // 'Needs Attention' on the Purchases page — manual_review
                // (ambiguous SMS match) and pending_activation (MikroTik
                // call failed) are the two states that need a human.
                'purchases' => Purchase::whereIn('status', ['manual_review', 'pending_activation'])->count(),
                'complaints' => Complaint::where('status', 'open')->count(),
                'routers_offline' => Router::where('connection_mode', 'live')->where('status', 'offline')->count(),
            ],
            'recent' => ActivityLog::with('user:id,name')
                ->latest()
                ->take(15)
                ->get(['id', 'user_id', 'action', 'description', 'created_at']),
        ]);
    }
}
