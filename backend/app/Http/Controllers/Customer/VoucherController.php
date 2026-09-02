<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\RouterPackageProfile;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoucherController extends Controller
{
    public function redeem(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string'],
            'router_id' => ['nullable', 'integer', 'exists:routers,id'],
        ]);

        $customer = $request->user();
        $code = strtoupper(trim($request->input('code')));

        return DB::transaction(function () use ($request, $customer, $code) {
            $voucher = Voucher::where('code', $code)->lockForUpdate()->first();

            if (! $voucher) {
                return response()->json(['message' => 'Invalid voucher code.'], 404);
            }

            if (! $voucher->isRedeemable()) {
                return response()->json([
                    'message' => $voucher->status === 'used'
                        ? 'This voucher has already been used.'
                        : 'This voucher has expired.',
                ], 422);
            }

            $routerId = $voucher->router_id;

            if (! $routerId) {
                if (! $request->filled('router_id')) {
                    $availableRouters = RouterPackageProfile::where('package_id', $voucher->package_id)
                        ->with('router:id,name,location')
                        ->get()
                        ->pluck('router')
                        ->filter()
                        ->values();

                    return response()->json([
                        'message' => 'This voucher is valid at more than one location — please choose one.',
                        'requires_router_selection' => true,
                        'available_routers' => $availableRouters,
                    ], 422);
                }

                $routerId = $request->integer('router_id');
            }

            $hasMapping = RouterPackageProfile::where('package_id', $voucher->package_id)
                ->where('router_id', $routerId)
                ->exists();

            if (! $hasMapping) {
                return response()->json([
                    'message' => 'This voucher\'s package is not available at that location.',
                ], 422);
            }

            $alreadyActive = $customer->purchases()
                ->where('router_id', $routerId)
                ->active()
                ->exists();

            if ($alreadyActive) {
                return response()->json([
                    'message' => 'You already have an active purchase on this router.',
                ], 422);
            }

            // Always 'voucher' fulfillment, regardless of whether the
            // underlying account was created live (Admin\VoucherController
            // ::generate()) or imported from a PDF — the account already
            // exists on the router either way, redeeming a code must never
            // create a second, different hotspot account. This used to
            // check $router->isManual() and route live-router redemptions
            // through 'live' fulfillment, which dispatched
            // ActivateHotspotUserJob and created an entirely separate
            // username/password account unrelated to the voucher code the
            // customer actually holds — the real code was left untracked
            // and un-expirable, while the phantom account was the only
            // thing the scheduler ever touched.
            $purchase = Purchase::create([
                'customer_id' => $customer->id,
                'package_id' => $voucher->package_id,
                'router_id' => $routerId,
                'voucher_id' => $voucher->id,
                'amount' => 0,
                'reference' => 'VOUCHER-' . $voucher->code,
                'payment_method' => 'momo', // voucher redemption doesn't really have a payment method
                'fulfillment_type' => 'voucher',
                'status' => 'completed', // payment already "done" (voucher = prepaid), voucher already assigned below
                'verified_at' => now(),
                'verification_method' => 'admin_manual', // admin generated the voucher
            ]);

            Payment::create([
                'customer_id' => $customer->id,
                'purchase_id' => $purchase->id,
                'reference' => 'VOUCHER-' . $voucher->code,
                'amount' => 0,
                'currency' => 'GHS',
                'status' => 'successful',
                'provider' => 'voucher',
                'paid_at' => now(),
            ]);

            // Assign THIS specific voucher directly — deliberately not
            // routed through PurchaseService::fulfill()/fulfillVoucher(),
            // which picks the oldest AVAILABLE voucher from inventory for
            // the package. That's correct for a payment-driven purchase
            // waiting on stock (see Admin\PurchaseController::assignVoucher()),
            // but here the customer already typed in an exact code — going
            // through fulfillVoucher() would silently assign a *different*
            // voucher on top of this one, burning two codes for one
            // redemption.
            $voucher->update([
                'status' => 'used',
                'used_by_customer_id' => $customer->id,
                'used_at' => now(),
                'assigned_to' => $customer->id,
                'assigned_at' => now(),
                'purchase_id' => $purchase->id,
            ]);

            $customer->update([
                'status' => 'active',
                'current_purchase_id' => $purchase->id,
            ]);

            ActivityLog::record(
                'voucher.redeemed',
                "Voucher {$voucher->code} redeemed.",
                ['customer_id' => $customer->id]
            );

            return response()->json([
                'message' => 'Voucher redeemed! Setting up your connection now.',
                'purchase' => $purchase->fresh(),
            ]);
        });
    }

    public function myVouchers(Request $request)
    {
        $customer = $request->user();

        $vouchers = Voucher::where(function ($q) use ($customer) {
            $q->where('used_by_customer_id', $customer->id)
                ->orWhere('assigned_to', $customer->id);
        })
            ->with('package:id,name')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 10));

        return response()->json($vouchers);
    }
}
