<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CreateOrderRequest;
use App\Http\Requests\Customer\VerifyOrderPaymentRequest;
use App\Models\InternetPackage;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Services\PaymentMatchingService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * The customer's own order history (Royal WiFi "Payment History" /
     * "Pending Payments" — Order and Payment are deliberately separate
     * models, see docs/DATABASE_SCHEMA.md, so this is distinct from the
     * existing GET /customer/payments which lists Paystack payments).
     */
    public function index(Request $request)
    {
        $orders = $request->user()->orders()
            ->with(['package:id,name', 'voucher:id,code'])
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return response()->json($orders);
    }

    /**
     * "BUY NOW" — creates the order and reference, but does NOT touch
     * voucher inventory at all. Assignment only ever happens after
     * payment verification (see VoucherAssignmentService).
     */
    public function store(CreateOrderRequest $request)
    {
        $customer = $request->user();
        $package = InternetPackage::findOrFail($request->package_id);

        $expiryMinutes = (int) (SystemSetting::get('order_expiry_minutes') ?? 60);

        $order = Order::create([
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'amount' => $package->price,
            'reference' => Order::generateReference(),
            'status' => 'pending',
            'expires_at' => now()->addMinutes($expiryMinutes),
        ]);

        return response()->json($this->paymentPagePayload($order), 201);
    }

    /**
     * The payment page — package, amount, MoMo number/account name
     * (from system_settings, never hardcoded), reference, and current
     * status. Ownership is checked by reference, not by exposing a
     * lookup-by-ID that could be enumerated.
     */
    public function show(Request $request, string $reference)
    {
        $order = $this->findOwnedOrder($request, $reference);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json($this->paymentPagePayload($order));
    }

    /**
     * Lightweight poll endpoint for the pending-payment page — deliberately
     * returns only status fields, not the full order/voucher payload,
     * since this is expected to be called repeatedly on a timer.
     */
    public function status(Request $request, string $reference)
    {
        $order = $this->findOwnedOrder($request, $reference);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json([
            'status' => $order->status,
            'has_voucher' => $order->voucher_id !== null,
        ]);
    }

    /**
     * Cosmetic "I have made payment" acknowledgement — flips
     * pending -> processing. Does NOT verify anything by itself; it's
     * just an honest signal to show the customer "we're watching for
     * your payment" instead of leaving them on a static page.
     */
    public function acknowledgePayment(Request $request, string $reference)
    {
        $order = $this->findOwnedOrder($request, $reference);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->status === 'pending') {
            $order->update(['status' => 'processing']);
        }

        return response()->json(['status' => $order->status]);
    }

    /**
     * The "Already made your payment?" fallback form.
     */
    public function verify(VerifyOrderPaymentRequest $request, string $reference, PaymentMatchingService $matcher)
    {
        $order = $this->findOwnedOrder($request, $reference);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if (! in_array($order->status, ['pending', 'processing'], true)) {
            // Already verified/completed/terminal — nothing to do, and
            // re-running matching against a non-pending order could
            // produce a confusing result.
            return response()->json([
                'success' => $order->status === 'completed' || $order->status === 'voucher_assigned',
                'status' => $order->status,
                'message' => 'This order is already ' . $order->status . '.',
            ]);
        }

        $result = $matcher->attemptManualMatch(
            $order,
            $request->input('transaction_id'),
            $request->input('reference_used')
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'status' => $order->fresh()->status,
        ]);
    }

    protected function findOwnedOrder(Request $request, string $reference): ?Order
    {
        return Order::where('reference', $reference)
            ->where('customer_id', $request->user()->id)
            ->with(['package', 'voucher'])
            ->first();
    }

    protected function paymentPagePayload(Order $order): array
    {
        return [
            'reference' => $order->reference,
            'status' => $order->status,
            'amount' => $order->amount,
            'package' => [
                'name' => $order->package->name,
                'duration_value' => $order->package->duration_value,
                'duration_unit' => $order->package->duration_unit,
            ],
            'momo_number' => SystemSetting::get('momo_number'),
            'momo_account_name' => SystemSetting::get('momo_account_name'),
            'expires_at' => $order->expires_at,
            'has_voucher' => $order->voucher_id !== null,
            'voucher' => $order->voucher_id ? [
                'code' => $order->voucher->code,
                'duration_days' => $order->voucher->duration_days,
            ] : null,
        ];
    }
}
