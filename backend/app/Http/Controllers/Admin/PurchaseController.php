<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\InternetPackage;
use App\Models\Purchase;
use App\Models\Router;
use App\Models\RouterPackageProfile;
use App\Services\PurchaseService;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    /**
     * Admin grants a package to a customer with no payment involved at
     * all — amount recorded as 0 (see the admin_grant migration for why
     * that matters for revenue reporting). Fulfills immediately, same
     * router-decides-fulfillment logic as every other purchase.
     */
    public function assign(Request $request, PurchaseService $purchaseService)
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'package_id' => ['required', 'integer', 'exists:internet_packages,id'],
            'router_id' => ['required', 'integer', 'exists:routers,id'],
        ]);

        $customer = Customer::findOrFail($validated['customer_id']);
        $package = InternetPackage::findOrFail($validated['package_id']);
        $router = Router::findOrFail($validated['router_id']);

        $hasMapping = RouterPackageProfile::where('package_id', $package->id)
            ->where('router_id', $router->id)
            ->exists();

        if (! $hasMapping) {
            return response()->json(['message' => 'This package is not available at that location.'], 422);
        }

        $purchase = Purchase::create([
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'router_id' => $router->id,
            'amount' => 0,
            'reference' => Purchase::generateReference(),
            'payment_method' => 'admin_grant',
            'fulfillment_type' => $router->isManual() ? 'voucher' : 'live',
            'status' => 'verified',
            'verified_at' => now(),
            'verification_method' => 'admin_manual',
            'verified_by' => $request->user()->id,
            'admin_notes' => 'Assigned by admin, no payment.',
        ]);

        $purchase = $purchaseService->fulfill($purchase);

        ActivityLog::record(
            'purchase.admin_assigned',
            "Admin assigned '{$package->name}' to {$customer->full_name} at no charge.",
            ['user_id' => $request->user()->id]
        );

        return response()->json($purchase->fresh(['voucher', 'package', 'router', 'customer']), 201);
    }

    public function index(Request $request)
    {
        $query = Purchase::with([
            'customer:id,full_name,phone,username',
            'package:id,name',
            'router:id,name',
            'voucher:id,code',
        ]);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($fulfillment = $request->query('fulfillment_type')) {
            $query->where('fulfillment_type', $fulfillment);
        }

        if ($routerId = $request->query('router_id')) {
            $query->where('router_id', $routerId);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('momo_transaction_id', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        return response()->json(
            $query->latest()->paginate($request->integer('per_page', 10))
        );
    }

    public function show(Purchase $purchase)
    {
        return response()->json(
            $purchase->load([
                'customer', 'package', 'router', 'voucher',
                'verifiedBy:id,name', 'smsLogs', 'payments',
            ])
        );
    }

    public function approve(Request $request, Purchase $purchase, PurchaseService $purchaseService)
    {
        if (! in_array($purchase->status, ['pending', 'processing', 'manual_review'], true)) {
            return response()->json([
                'message' => "Only pending/processing/manual_review purchases can be approved. This one is '{$purchase->status}'.",
            ], 422);
        }

        $purchase = $purchaseService->verifyAndFulfill(
            $purchase,
            'admin_manual',
            adminUserId: $request->user()->id
        );

        ActivityLog::record(
            'purchase.admin_approved',
            "Admin manually approved purchase {$purchase->reference}.",
            ['user_id' => $request->user()->id]
        );

        return response()->json($purchase->fresh(['voucher', 'package', 'router']));
    }

    public function reject(Request $request, Purchase $purchase)
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if (in_array($purchase->status, ['completed', 'voucher_assigned', 'active', 'failed', 'cancelled'], true)) {
            return response()->json([
                'message' => "Cannot reject a purchase that is already '{$purchase->status}'.",
            ], 422);
        }

        $purchase->update([
            'status' => 'failed',
            'admin_notes' => $request->input('reason'),
        ]);

        ActivityLog::record(
            'purchase.admin_rejected',
            "Admin rejected purchase {$purchase->reference}: {$request->input('reason')}",
            ['user_id' => $request->user()->id]
        );

        return response()->json($purchase->fresh());
    }

    public function cancel(Request $request, Purchase $purchase)
    {
        if (! in_array($purchase->status, ['pending', 'processing', 'queued'], true)) {
            return response()->json([
                'message' => "Only a pending/processing/queued purchase can be cancelled. This one is '{$purchase->status}'.",
            ], 422);
        }

        $purchase->update(['status' => 'cancelled']);

        ActivityLog::record(
            'purchase.cancelled',
            "Admin cancelled purchase {$purchase->reference}.",
            ['user_id' => $request->user()->id]
        );

        return response()->json($purchase->fresh());
    }

    public function suspend(Request $request, Purchase $purchase, PurchaseService $purchaseService)
    {
        if (! in_array($purchase->status, ['active', 'voucher_assigned', 'completed'], true)) {
            return response()->json([
                'message' => "Only an active purchase can be suspended. This one is '{$purchase->status}'.",
            ], 422);
        }

        $mikrotikResult = $purchaseService->suspendPurchase($purchase);

        ActivityLog::record(
            'purchase.suspended',
            "Admin suspended purchase {$purchase->reference}." .
                ($purchase->isLive() && ! $mikrotikResult['success'] ? " (MikroTik side-effect failed: {$mikrotikResult['error']})" : ''),
            ['user_id' => $request->user()->id]
        );

        return response()->json($purchase->fresh());
    }

    public function activate(Request $request, Purchase $purchase, PurchaseService $purchaseService)
    {
        if ($purchase->status !== 'suspended') {
            return response()->json([
                'message' => "Only a suspended purchase can be re-activated. This one is '{$purchase->status}'.",
            ], 422);
        }

        $mikrotikResult = $purchaseService->activatePurchase($purchase);

        ActivityLog::record(
            'purchase.reactivated',
            "Admin reactivated purchase {$purchase->reference}." .
                ($purchase->isLive() && ! $mikrotikResult['success'] ? " (MikroTik side-effect failed: {$mikrotikResult['error']})" : ''),
            ['user_id' => $request->user()->id]
        );

        return response()->json($purchase->fresh());
    }

    public function retryActivation(Request $request, Purchase $purchase)
    {
        if ($purchase->status !== 'pending_activation') {
            return response()->json([
                'message' => 'This purchase is not awaiting activation retry.',
            ], 422);
        }

        $purchase->update(['status' => 'verified']);
        app(PurchaseService::class)->fulfill($purchase);

        ActivityLog::record(
            'purchase.retry_activation',
            "Admin retried activation for purchase {$purchase->reference}.",
            ['user_id' => $request->user()->id]
        );

        return response()->json($purchase->fresh(['voucher', 'package', 'router']));
    }

    /**
     * Retry voucher assignment for purchases stuck verified with no
     * voucher (inventory ran out at payment time).
     */
    public function assignVoucher(Request $request, Purchase $purchase, PurchaseService $purchaseService)
    {
        if ($purchase->status !== 'verified' || $purchase->voucher_id !== null) {
            return response()->json([
                'message' => 'This purchase is not waiting on voucher inventory.',
            ], 422);
        }

        $purchase = $purchaseService->fulfill($purchase);

        if ($purchase->voucher_id === null) {
            return response()->json([
                'message' => 'Still no voucher available for this package. Import more vouchers and try again.',
                'purchase' => $purchase,
            ], 422);
        }

        ActivityLog::record(
            'purchase.manual_voucher_retry',
            "Admin manually retried voucher assignment for purchase {$purchase->reference}.",
            ['user_id' => $request->user()->id]
        );

        return response()->json($purchase->fresh(['voucher', 'package']));
    }
}
