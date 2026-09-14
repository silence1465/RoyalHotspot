<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\InitializePaymentRequest;
use App\Http\Requests\Customer\VerifyOrderPaymentRequest as VerifyPaymentRequest;
use App\Models\InternetPackage;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Router;
use App\Models\SystemSetting;
use App\Services\PaymentMatchingService;
use App\Services\PaystackService;
use App\Services\PurchaseService;
use App\Services\CheckoutFeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    /**
     * Unified purchase history — replaces both the old
     * PaymentController::index (Paystack payments) and
     * OrderController::index (MoMo orders).
     */
    public function index(Request $request)
    {
        $purchases = $request->user()->purchases()
            ->with(['package:id,name', 'voucher:id,code', 'router:id,name'])
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return response()->json($purchases);
    }

    /**
     * Create a purchase via the payment gateway selected by the customer.
     * Paystack → creates Purchase + Payment + redirects to Paystack checkout.
     * MoMo → creates Purchase + returns payment page data.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'in:paystack,momo'],
            'router_id' => ['nullable', 'integer', 'exists:routers,id'],
        ]);
        $paymentMethod = $validated['payment_method'];

        if (! filter_var(SystemSetting::get("{$paymentMethod}_enabled", '1'), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json([
                'message' => ucfirst($paymentMethod) . ' payments are currently unavailable. Please choose another payment method.',
                'errors' => ['payment_method' => ['This payment method is currently disabled.']],
            ], 422);
        }

        $router = isset($validated['router_id']) ? Router::find($validated['router_id']) : null;
        if ($router && ! $router->{"{$paymentMethod}_enabled"}) {
            return response()->json([
                'message' => ucfirst($paymentMethod).' payments are not available at this location. Please choose another payment method.',
                'errors' => ['payment_method' => ['This payment method is disabled for the selected router.']],
            ], 422);
        }

        if ($paymentMethod === 'paystack') {
            return $this->storePaystack($request);
        }

        return $this->storeMomo($request);
    }

    protected function storePaystack(Request $request)
    {
        // Reuse existing validation (checks router exists, package
        // active, router is live, profile mapping exists)
        $validated = app(InitializePaymentRequest::class)->validated();

        $customer = $request->user();
        $package = InternetPackage::findOrFail($validated['package_id']);
        $pricing = app(CheckoutFeeService::class)->calculate($package->price, 'paystack');
        $router = Router::findOrFail($validated['router_id']);

        $fulfillmentType = $router->isManual() ? 'voucher' : 'live';

        $email = $customer->email;
        if (! $email) {
            $fallbackDomain = trim((string) config('services.paystack.fallback_email_domain'));

            if (! $fallbackDomain || ! filter_var("customer@{$fallbackDomain}", FILTER_VALIDATE_EMAIL)) {
                return response()->json([
                    'message' => 'Please add a valid email address to your profile before paying with Paystack.',
                ], 422);
            }

            $email = "customer{$customer->id}@{$fallbackDomain}";
        }
        $paystack = app(PaystackService::class);

        [$purchase, $payment, $reference] = DB::transaction(function () use ($customer, $package, $router, $fulfillmentType, $paystack, $pricing) {
            $reference = $paystack->generateReference();

            $purchase = Purchase::create([
                'customer_id' => $customer->id,
                'package_id' => $package->id,
                'router_id' => $router->id,
                'subtotal' => $pricing['subtotal'],
                'payment_fee' => $pricing['payment_fee'],
                'amount' => $pricing['total'],
                'reference' => $reference,
                'payment_method' => 'paystack',
                'fulfillment_type' => $fulfillmentType,
                'status' => 'pending',
            ]);

            $payment = Payment::create([
                'customer_id' => $customer->id,
                'purchase_id' => $purchase->id,
                'reference' => $reference,
                'amount' => $pricing['total'],
                'currency' => 'GHS',
                'status' => 'pending',
                'provider' => 'paystack',
            ]);

            return [$purchase, $payment, $reference];
        });

        $amountSubunits = (int) round(((float) $pricing['total']) * 100);

        $result = $paystack->initializeTransaction($email, $amountSubunits, $reference, [
            'customer_id' => $customer->id,
            'purchase_id' => $purchase->id,
            'package_id' => $package->id,
        ]);

        if (! $result['success']) {
            $payment->update([
                'status' => 'failed',
                'provider_response' => json_encode($result),
            ]);
            $purchase->update(['status' => 'failed']);

            return response()->json([
                'message' => 'Could not start payment right now. Please try again shortly.',
            ], 502);
        }

        $payment->update(['provider_response' => json_encode($result['raw'] ?? $result)]);

        return response()->json([
            'authorization_url' => $result['authorization_url'],
            'reference' => $reference,
        ]);
    }

    protected function storeMomo(Request $request)
    {
        // Router selection is required for both gateways. It decides the
        // fulfillment type and scopes queueing to one physical hotspot.
        $validated = app(InitializePaymentRequest::class)->validated();

        $customer = $request->user();
        $package = InternetPackage::findOrFail($validated['package_id']);
        $pricing = app(CheckoutFeeService::class)->calculate($package->price, 'momo');

        // For MoMo, router is optional at purchase time — fulfillment
        // type is determined later if a router is involved, or defaults
        // to voucher since MoMo purchases typically don't need a
        // specific router chosen up front.
        $routerId = $validated['router_id'];
        $router = Router::findOrFail($routerId);
        $fulfillmentType = $router->isManual() ? 'voucher' : 'live';

        $expiryMinutes = (int) (SystemSetting::get('order_expiry_minutes') ?? 60);

        $purchase = Purchase::create([
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'router_id' => $routerId,
            'subtotal' => $pricing['subtotal'],
            'payment_fee' => $pricing['payment_fee'],
            'amount' => $pricing['total'],
            'reference' => Purchase::generateReference(),
            'payment_method' => 'momo',
            'bonus_duration_minutes' => $package->momoBonusMinutes(),
            'fulfillment_type' => $fulfillmentType,
            'status' => 'pending',
            'expires_at' => now()->addMinutes($expiryMinutes),
        ]);

        return response()->json($this->paymentPagePayload($purchase), 201);
    }

    public function show(Request $request, string $reference)
    {
        $purchase = $this->findOwned($request, $reference);

        if (! $purchase) {
            return response()->json(['message' => 'Purchase not found.'], 404);
        }

        return response()->json($this->paymentPagePayload($purchase));
    }

    public function status(Request $request, string $reference)
    {
        $purchase = $this->findOwned($request, $reference);

        if (! $purchase) {
            return response()->json(['message' => 'Purchase not found.'], 404);
        }

        return response()->json([
            'status' => $purchase->status,
            'has_voucher' => $purchase->voucher_id !== null,
            'fulfillment_type' => $purchase->fulfillment_type,
            'connection_ready' => $this->connectionReady($purchase),
        ]);
    }

    /**
     * Activate a purchase sitting in 'queued' — blocked outright if
     * something else is still active on the same router (see
     * PurchaseService, no bypass exists).
     */
    public function activate(Request $request, int $purchase, PurchaseService $purchaseService)
    {
        $purchaseModel = Purchase::where('id', $purchase)
            ->where('customer_id', $request->user()->id)
            ->first();

        if (! $purchaseModel) {
            return response()->json(['message' => 'Purchase not found.'], 404);
        }

        $result = $purchaseService->activateQueuedPurchase($purchaseModel);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'purchase' => $purchaseModel->fresh(['package', 'voucher', 'router']),
        ], $result['success'] ? 200 : 422);
    }

    /**
     * Starts a voucher purchase's calendar expiry window. Called by the
     * frontend right before it submits the actual RouterOS hotspot login
     * form (Dashboard.jsx handleConnectToWifi) — the earliest point this
     * app can observe as "the customer is actually connecting now" rather
     * than "the voucher code changed hands". Live purchases start through
     * HotspotSessionService once the customer connects at the router.
     */
    public function connect(Request $request, int $purchase, PurchaseService $purchaseService)
    {
        $purchaseModel = Purchase::where('id', $purchase)
            ->where('customer_id', $request->user()->id)
            ->first();

        if (! $purchaseModel) {
            return response()->json(['message' => 'Purchase not found.'], 404);
        }

        $purchaseModel = $purchaseService->activateVoucherAccess($purchaseModel);

        return response()->json($purchaseModel->fresh(['package', 'voucher', 'router']));
    }

    public function acknowledgePayment(Request $request, string $reference)
    {
        $purchase = $this->findOwned($request, $reference);

        if (! $purchase) {
            return response()->json(['message' => 'Purchase not found.'], 404);
        }

        if ($purchase->status === 'pending') {
            $purchase->update(['status' => 'processing']);
        }

        return response()->json(['status' => $purchase->status]);
    }

    public function verify(VerifyPaymentRequest $request, string $reference, PaymentMatchingService $matcher)
    {
        $purchase = $this->findOwned($request, $reference);

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
            $request->input('reference_used')
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'status' => $purchase->fresh()->status,
        ]);
    }

    protected function findOwned(Request $request, string $reference): ?Purchase
    {
        return Purchase::where('reference', $reference)
            ->where('customer_id', $request->user()->id)
            ->with(['package', 'voucher', 'router:id,name'])
            ->first();
    }

    protected function paymentPagePayload(Purchase $purchase): array
    {
        return [
            'reference' => $purchase->reference,
            'status' => $purchase->status,
            'amount' => $purchase->amount,
            'subtotal' => $purchase->subtotal,
            'payment_fee' => $purchase->payment_fee,
            'payment_method' => $purchase->payment_method,
            'fulfillment_type' => $purchase->fulfillment_type,
            'package' => [
                'name' => $purchase->package->name,
                'duration_value' => $purchase->package->duration_value,
                'duration_unit' => $purchase->package->duration_unit,
            ],
            'router' => $purchase->router ? ['id' => $purchase->router->id, 'name' => $purchase->router->name] : null,
            'momo_number' => SystemSetting::get('momo_number'),
            'momo_account_name' => SystemSetting::get('momo_account_name'),
            'expires_at' => $purchase->expires_at,
            'has_voucher' => $purchase->voucher_id !== null,
            'connection_ready' => $this->connectionReady($purchase),
            'voucher' => $purchase->voucher_id ? [
                'code' => $purchase->voucher->code,
                'duration_days' => $purchase->voucher->duration_days,
            ] : null,
        ];
    }

    protected function connectionReady(Purchase $purchase): bool
    {
        if ($purchase->isVoucher()) {
            return $purchase->voucher_id !== null;
        }

        if (! $purchase->customer_id || ! $purchase->router_id || $purchase->status !== 'active') {
            return false;
        }

        return $purchase->hotspotUser()
            ->where('disabled', false)
            ->whereNotNull('password')
            ->exists();
    }
}
