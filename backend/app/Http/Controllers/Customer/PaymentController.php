<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\InitializePaymentRequest;
use App\Models\InternetPackage;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $payments = $request->user()->payments()
            ->with('subscription:id,package_id')
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return response()->json($payments);
    }

    public function initialize(InitializePaymentRequest $request, PaystackService $paystack)
    {
        $customer = $request->user();
        $package = InternetPackage::findOrFail($request->package_id);

        // A temporary, simple guard against double-active state on the
        // same router — full renewal/stacking semantics are an open
        // question flagged in docs/PROJECT_OVERVIEW.md and get resolved
        // properly once Phase 9/10 activation + expiry are both in place.
        $alreadyActive = $customer->subscriptions()
            ->where('router_id', $request->router_id)
            ->where('status', 'active')
            ->exists();

        if ($alreadyActive) {
            return response()->json([
                'message' => 'You already have an active subscription on this router. Wait for it to expire before buying another.',
            ], 422);
        }

        // Paystack requires a valid email even though a customer account
        // here doesn't require one (see customers.email nullable in
        // docs/DATABASE_SCHEMA.md). Synthesize a stable, uniquely
        // identifiable placeholder rather than blocking the purchase —
        // it's never used to actually contact the customer.
        $email = $customer->email ?: "customer{$customer->id}@no-email.hotspotbilling.local";

        // Create the pending subscription + payment in a short,
        // network-call-free transaction.
        [$subscription, $payment, $reference] = DB::transaction(function () use ($customer, $package, $request, $paystack) {
            $reference = $paystack->generateReference();

            $subscription = Subscription::create([
                'customer_id' => $customer->id,
                'package_id' => $package->id,
                'router_id' => $request->router_id,
                'status' => 'pending',
                'amount' => $package->price,
            ]);

            $payment = Payment::create([
                'customer_id' => $customer->id,
                'subscription_id' => $subscription->id,
                'reference' => $reference,
                'amount' => $package->price,
                'currency' => 'GHS',
                'status' => 'pending',
                'provider' => 'paystack',
            ]);

            return [$subscription, $payment, $reference];
        });

        // Paystack amounts are in the smallest currency unit (pesewas for
        // GHS) — multiply by 100. This call happens OUTSIDE the DB
        // transaction above; holding a transaction open across an
        // external HTTP round-trip would tie up a DB connection for the
        // duration of that request.
        $amountSubunits = (int) round($package->price * 100);

        $result = $paystack->initializeTransaction($email, $amountSubunits, $reference, [
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'package_id' => $package->id,
        ]);

        if (! $result['success']) {
            $payment->update([
                'status' => 'failed',
                'provider_response' => json_encode($result),
            ]);
            $subscription->update(['status' => 'cancelled']);

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
}
