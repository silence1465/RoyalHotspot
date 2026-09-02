<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\InternetPackage;
use App\Models\Purchase;
use App\Models\Router;
use App\Models\SystemSetting;
use App\Services\PaymentMatchingService;
use App\Services\CheckoutFeeService;
use Illuminate\Http\Request;

/**
 * The whole guest checkout surface — unauthenticated, no customer_id,
 * always payment_method 'momo' and fulfillment_type 'live'. See
 * PurchaseController (customer) for the registered-customer equivalent;
 * kept as a SEPARATE controller rather than folded into that one because
 * the auth model, validation, and allowed operations are genuinely
 * different enough (no ownership check via auth — ownership here is
 * "do you know the reference/phone number", not a Sanctum token).
 */
class GuestPurchaseController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'package_id' => ['required', 'integer', 'exists:internet_packages,id'],
            'router_id' => ['required', 'integer', 'exists:routers,id'],
            'guest_phone' => ['required', 'string', 'min:9', 'max:15'],
        ]);

        $package = InternetPackage::findOrFail($validated['package_id']);
        $router = Router::findOrFail($validated['router_id']);
        $pricing = app(CheckoutFeeService::class)->calculate($package->price);

        if (! $package->available_to_guests) {
            return response()->json(['message' => 'This package is not available for guest purchase.'], 422);
        }

        if ($router->isManual()) {
            return response()->json(['message' => 'Guest purchase isn\'t available at this location right now.'], 422);
        }

        $hasMapping = $router->packageProfiles()->where('package_id', $package->id)->exists();

        if (! $hasMapping) {
            return response()->json(['message' => 'This package is not available at that location.'], 422);
        }

        $expiryMinutes = (int) (SystemSetting::get('order_expiry_minutes') ?? 60);

        $purchase = Purchase::create([
            'customer_id' => null,
            'package_id' => $package->id,
            'router_id' => $router->id,
            'subtotal' => $pricing['subtotal'],
            'payment_fee' => $pricing['payment_fee'],
            'amount' => $pricing['total'],
            'reference' => Purchase::generateReference(),
            'payment_method' => 'momo',
            'bonus_duration_minutes' => $package->momoBonusMinutes(),
            'fulfillment_type' => 'live',
            'status' => 'pending',
            'expires_at' => now()->addMinutes($expiryMinutes),
            'guest_phone' => $this->normalizePhone($validated['guest_phone']),
        ]);

        return response()->json($this->paymentPagePayload($purchase), 201);
    }

    public function show(string $reference)
    {
        $purchase = Purchase::where('reference', $reference)->whereNull('customer_id')->first();

        if (! $purchase) {
            return response()->json(['message' => 'Purchase not found.'], 404);
        }

        return response()->json($this->paymentPagePayload($purchase));
    }

    public function status(string $reference)
    {
        $purchase = Purchase::where('reference', $reference)->whereNull('customer_id')->first();

        if (! $purchase) {
            return response()->json(['message' => 'Purchase not found.'], 404);
        }

        return response()->json([
            'status' => $purchase->status,
            'has_code' => $purchase->guest_code !== null,
        ]);
    }

    public function acknowledgePayment(string $reference)
    {
        $purchase = Purchase::where('reference', $reference)->whereNull('customer_id')->first();

        if (! $purchase) {
            return response()->json(['message' => 'Purchase not found.'], 404);
        }

        if ($purchase->status === 'pending') {
            $purchase->update(['status' => 'processing']);
        }

        return response()->json(['status' => $purchase->status]);
    }

    public function verify(Request $request, string $reference, PaymentMatchingService $matcher)
    {
        $request->validate([
            'transaction_id' => ['nullable', 'string', 'max:100'],
            'reference_used' => ['nullable', 'string', 'max:100'],
        ]);

        $purchase = Purchase::where('reference', $reference)->whereNull('customer_id')->first();

        if (! $purchase) {
            return response()->json(['message' => 'Purchase not found.'], 404);
        }

        if (! in_array($purchase->status, ['pending', 'processing'], true)) {
            return response()->json([
                'success' => $purchase->isActive(),
                'status' => $purchase->status,
                'message' => 'This purchase is already ' . $purchase->status . '.',
            ]);
        }

        $result = $matcher->attemptManualMatch(
            $purchase,
            $request->input('transaction_id'),
            $request->input('reference_used') ?? $reference
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'status' => $purchase->fresh()->status,
        ]);
    }

    /**
     * Phone-number recovery — the guest's substitute for a session. Every
     * code ever bought with this number, valid or expired, so it can
     * double as "did I lose my code" AND "what did I even buy before".
     */
    public function lookup(Request $request)
    {
        $request->validate([
            'phone' => ['required', 'string', 'min:9', 'max:15'],
        ]);

        $phone = $this->normalizePhone($request->input('phone'));

        $purchases = Purchase::where('guest_phone', $phone)
            ->whereNotNull('guest_code')
            ->with('package:id,name,duration_value,duration_unit')
            ->latest()
            ->get()
            ->map(fn ($p) => [
                'reference' => $p->reference,
                'code' => $p->guest_code,
                'package' => $p->package->name,
                'is_valid' => in_array($p->status, ['active', 'completed']) && (! $p->expires_at || $p->expires_at->isFuture()),
                'status' => $p->status,
                'expires_at' => $p->expires_at,
                'purchased_at' => $p->created_at,
            ]);

        return response()->json(['purchases' => $purchases]);
    }

    protected function normalizePhone(string $phone): string
    {
        // Strip everything but digits, so "024 543 3375", "+233245433375",
        // and "0245433375" all match the same underlying number when
        // looked up later — matches the same normalization the SMS
        // parser already applies to parsed_phone.
        return preg_replace('/\D/', '', $phone);
    }

    protected function paymentPagePayload(Purchase $purchase): array
    {
        return [
            'reference' => $purchase->reference,
            'status' => $purchase->status,
            'amount' => $purchase->amount,
            'subtotal' => $purchase->subtotal,
            'payment_fee' => $purchase->payment_fee,
            'package' => [
                'name' => $purchase->package->name,
                'duration_value' => $purchase->package->duration_value,
                'duration_unit' => $purchase->package->duration_unit,
            ],
            'momo_number' => SystemSetting::get('momo_number'),
            'momo_account_name' => SystemSetting::get('momo_account_name'),
            'expires_at' => $purchase->expires_at,
            'has_code' => $purchase->guest_code !== null,
            'code' => $purchase->guest_code,
        ];
    }
}
