<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Services\VoucherAssignmentService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with(['customer:id,full_name,phone,username', 'package:id,name', 'voucher:id,code']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('momo_transaction_id', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->latest()->paginate($request->integer('per_page', 10))
        );
    }

    public function show(Order $order)
    {
        return response()->json(
            $order->load(['customer', 'package', 'voucher', 'verifiedBy:id,name', 'smsLogs'])
        );
    }

    /**
     * Manual approval — for orders an admin has independently confirmed
     * were paid (e.g. checked the MoMo merchant portal directly) even
     * though no matching SMS ever arrived, or for resolving a
     * 'manual_review' order after inspecting the evidence. Goes through
     * the exact same VoucherAssignmentService as the automatic paths —
     * the only difference is verification_method='admin_manual' and
     * verified_by being set.
     */
    public function approve(Request $request, Order $order, VoucherAssignmentService $assignmentService)
    {
        if (! in_array($order->status, ['pending', 'processing', 'manual_review'], true)) {
            return response()->json([
                'message' => "Only pending/processing/manual_review orders can be approved. This one is '{$order->status}'.",
            ], 422);
        }

        $order = $assignmentService->verifyAndAssign(
            $order,
            'admin_manual',
            adminUserId: $request->user()->id
        );

        ActivityLog::record(
            'order.admin_approved',
            "Admin manually approved order {$order->reference}.",
            ['user_id' => $request->user()->id]
        );

        return response()->json($order->fresh(['voucher', 'package']));
    }

    /**
     * Reject a suspicious/unverifiable order — terminal, matches
     * business rule that manual approval/rejection must be logged.
     */
    public function reject(Request $request, Order $order)
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if (in_array($order->status, ['completed', 'voucher_assigned', 'failed', 'cancelled'], true)) {
            return response()->json([
                'message' => "Cannot reject an order that is already '{$order->status}'.",
            ], 422);
        }

        $order->update([
            'status' => 'failed',
            'admin_notes' => $request->input('reason'),
        ]);

        ActivityLog::record(
            'order.admin_rejected',
            "Admin rejected order {$order->reference}: {$request->input('reason')}",
            ['user_id' => $request->user()->id]
        );

        return response()->json($order->fresh());
    }

    public function cancel(Request $request, Order $order)
    {
        if (! in_array($order->status, ['pending', 'processing'], true)) {
            return response()->json([
                'message' => "Only a pending/processing order can be cancelled. This one is '{$order->status}'.",
            ], 422);
        }

        $order->update(['status' => 'cancelled']);

        ActivityLog::record(
            'order.cancelled',
            "Admin cancelled order {$order->reference}.",
            ['user_id' => $request->user()->id]
        );

        return response()->json($order->fresh());
    }

    /**
     * Manual retry for an order stuck 'verified' with no voucher
     * (inventory ran out at the time of payment) — same "retry" pattern
     * as the original hotspot subscription flow's retry-activation.
     */
    public function assignVoucher(Request $request, Order $order, VoucherAssignmentService $assignmentService)
    {
        if ($order->status !== 'verified' || $order->voucher_id !== null) {
            return response()->json([
                'message' => 'This order is not waiting on voucher inventory.',
            ], 422);
        }

        $order = $assignmentService->attemptAssignment($order);

        if ($order->voucher_id === null) {
            return response()->json([
                'message' => 'Still no voucher available for this package. Import more vouchers and try again.',
                'order' => $order,
            ], 422);
        }

        ActivityLog::record(
            'order.manual_voucher_retry',
            "Admin manually retried voucher assignment for order {$order->reference}.",
            ['user_id' => $request->user()->id]
        );

        return response()->json($order->fresh(['voucher', 'package']));
    }
}
