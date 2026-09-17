<?php

namespace Tests\Feature;

use App\Jobs\ActivateHotspotUserJob;
use App\Models\Customer;
use App\Models\InternetPackage;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Router;
use App\Models\RouterPackageProfile;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PaystackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SecurityPenetrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_admin_and_customer_routes_reject_anonymous_requests(): void
    {
        $this->getJson('/api/v1/admin/settings')->assertUnauthorized();
        $this->getJson('/api/v1/customer/dashboard')->assertUnauthorized();
        $this->get('/api/v1/admin/settings')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_customer_and_admin_tokens_cannot_cross_security_boundaries(): void
    {
        $customer = Customer::factory()->active()->create();
        $admin = User::factory()->admin()->create();

        $customerToken = $customer->createToken('penetration-customer', ['customer'])->plainTextToken;
        $adminToken = $admin->createToken('penetration-admin', ['admin'])->plainTextToken;

        $adminBoundary = $this->withToken($customerToken)->getJson('/api/v1/admin/settings');
        $customerBoundary = $this->withToken($adminToken)->getJson('/api/v1/customer/dashboard');

        $this->assertContains($adminBoundary->status(), [401, 403]);
        $this->assertContains($customerBoundary->status(), [401, 403]);
    }

    public function test_customer_cannot_read_or_mutate_another_customers_purchase(): void
    {
        [$owner, $attacker] = [Customer::factory()->active()->create(), Customer::factory()->active()->create()];
        $purchase = $this->makePurchase($owner, 'RW-OWNED1');
        $token = $attacker->createToken('penetration-attacker', ['customer'])->plainTextToken;

        $this->withToken($token)->getJson("/api/v1/customer/purchases/{$purchase->reference}")->assertNotFound();
        $this->withToken($token)->postJson("/api/v1/customer/purchases/{$purchase->id}/activate")->assertNotFound();
        $this->withToken($token)->postJson("/api/v1/customer/purchases/{$purchase->id}/connect")->assertNotFound();
    }

    public function test_checkout_ignores_client_supplied_price_and_fee_values(): void
    {
        $customer = Customer::factory()->active()->create();
        $router = Router::factory()->create(['connection_mode' => 'live']);
        $package = InternetPackage::factory()->create(['price' => 50, 'status' => 'active']);
        RouterPackageProfile::create([
            'router_id' => $router->id,
            'package_id' => $package->id,
            'profile_name' => 'secure-profile',
            'shared_users' => 1,
        ]);
        SystemSetting::set('momo_enabled', true);
        $token = $customer->createToken('penetration-customer', ['customer'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/customer/purchases', [
            'package_id' => $package->id,
            'router_id' => $router->id,
            'payment_method' => 'momo',
            'subtotal' => 0.01,
            'payment_fee' => -500,
            'amount' => 0.01,
            'status' => 'active',
        ])->assertCreated();

        $purchase = Purchase::where('reference', $response->json('reference'))->firstOrFail();
        $this->assertSame('50.00', $purchase->subtotal);
        $this->assertSame('0.00', $purchase->payment_fee);
        $this->assertSame('50.00', $purchase->amount);
        $this->assertSame('pending', $purchase->status);
    }

    public function test_unsigned_payment_webhooks_and_unauthenticated_sms_are_rejected(): void
    {
        $this->postJson('/api/v1/webhooks/paystack', [
            'event' => 'charge.success',
            'data' => ['reference' => 'RW-FAKE'],
        ])->assertUnauthorized();

        config(['services.sms_forwarder.token' => 'penetration-test-secret']);
        $this->postJson('/api/v1/webhooks/sms-payment', ['text' => 'fake payment'])->assertUnauthorized();
        $this->withHeader('X-Forwarder-Token', 'wrong-secret')
            ->postJson('/api/v1/webhooks/sms-payment', ['text' => 'fake payment'])
            ->assertUnauthorized();
    }

    public function test_repeated_login_attacks_are_rate_limited_without_account_enumeration(): void
    {
        User::factory()->admin()->create([
            'email' => 'known-admin@example.test',
            'password' => 'correct-password',
        ]);

        $known = $this->postJson('/api/v1/admin/login', [
            'email' => 'known-admin@example.test',
            'password' => 'wrong-password',
        ]);
        $unknown = $this->postJson('/api/v1/admin/login', [
            'email' => 'unknown-admin@example.test',
            'password' => 'wrong-password',
        ]);

        $known->assertUnauthorized();
        $unknown->assertUnauthorized();
        $this->assertSame($known->json('message'), $unknown->json('message'));

        for ($attempt = 1; $attempt <= 7; $attempt++) {
            $response = $this->postJson('/api/v1/customer/login', [
                'username' => 'brute-force-target',
                'password' => 'wrong-password',
            ]);
        }

        $response->assertStatus(429);
    }

    public function test_paystack_checkout_uses_server_price_and_adds_exactly_two_percent(): void
    {
        $customer = Customer::factory()->active()->create(['email' => null]);
        $router = Router::factory()->create(['connection_mode' => 'live']);
        $package = InternetPackage::factory()->create(['price' => 100, 'status' => 'active']);
        RouterPackageProfile::create([
            'router_id' => $router->id,
            'package_id' => $package->id,
            'profile_name' => 'paystack-test-profile',
            'shared_users' => 1,
        ]);
        SystemSetting::set('paystack_enabled', true);
        config(['services.paystack.fallback_email_domain' => 'freedomdata.shop']);

        $paystack = \Mockery::mock(PaystackService::class);
        $paystack->shouldReceive('generateReference')->once()->andReturn('HBS_PAYSTACK_TEST');
        $paystack->shouldReceive('initializeTransaction')
            ->once()
            ->withArgs(fn ($email, $amount, $reference, $metadata) => $email === "customer{$customer->id}@freedomdata.shop"
                && $amount === 10200
                && $reference === 'HBS_PAYSTACK_TEST'
                && $metadata['package_id'] === $package->id)
            ->andReturn([
                'success' => true,
                'authorization_url' => 'https://checkout.paystack.com/test-only',
                'reference' => 'HBS_PAYSTACK_TEST',
                'raw' => ['status' => true],
            ]);
        $this->app->instance(PaystackService::class, $paystack);

        $token = $customer->createToken('paystack-test', ['customer'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/customer/purchases', [
            'package_id' => $package->id,
            'router_id' => $router->id,
            'payment_method' => 'paystack',
            'amount' => 0.01,
        ])->assertOk()
            ->assertJsonPath('reference', 'HBS_PAYSTACK_TEST')
            ->assertJsonPath('authorization_url', 'https://checkout.paystack.com/test-only');

        $purchase = Purchase::where('reference', 'HBS_PAYSTACK_TEST')->firstOrFail();
        $payment = Payment::where('reference', 'HBS_PAYSTACK_TEST')->firstOrFail();
        $this->assertSame('100.00', $purchase->subtotal);
        $this->assertSame('2.00', $purchase->payment_fee);
        $this->assertSame('102.00', $purchase->amount);
        $this->assertSame('102.00', $payment->amount);
        $this->assertSame('pending', $payment->status);
    }

    public function test_signed_paystack_payment_activates_once_and_duplicate_webhook_is_idempotent(): void
    {
        Queue::fake();
        $customer = Customer::factory()->active()->create(['email' => 'paystack@example.com']);
        $router = Router::factory()->create([
            'connection_mode' => 'live',
            'paystack_enabled' => true,
        ]);
        $package = InternetPackage::factory()->create(['price' => 100, 'status' => 'active']);
        RouterPackageProfile::create([
            'router_id' => $router->id,
            'package_id' => $package->id,
            'profile_name' => 'paystack-live-profile',
            'shared_users' => 1,
        ]);
        SystemSetting::set('paystack_enabled', true);

        $paystack = \Mockery::mock(PaystackService::class);
        $paystack->shouldReceive('generateReference')->once()->andReturn('HBS_PAYSTACK_SUCCESS');
        $paystack->shouldReceive('initializeTransaction')->once()->andReturn([
            'success' => true,
            'authorization_url' => 'https://checkout.paystack.com/test-success',
            'reference' => 'HBS_PAYSTACK_SUCCESS',
            'raw' => ['status' => true],
        ]);
        $paystack->shouldReceive('verifyWebhookSignature')->twice()->andReturnTrue();
        $paystack->shouldReceive('verifyTransaction')->once()->with('HBS_PAYSTACK_SUCCESS')->andReturn([
            'success' => true,
            'data' => [
                'status' => 'success',
                'amount' => 10200,
                'channel' => 'card',
                'reference' => 'HBS_PAYSTACK_SUCCESS',
            ],
        ]);
        $this->app->instance(PaystackService::class, $paystack);

        $token = $customer->createToken('paystack-success', ['customer'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/customer/purchases', [
            'package_id' => $package->id,
            'router_id' => $router->id,
            'payment_method' => 'paystack',
        ])->assertOk()->assertJsonPath('reference', 'HBS_PAYSTACK_SUCCESS');

        $this->withToken($token)
            ->postJson('/api/v1/customer/purchases/HBS_PAYSTACK_SUCCESS/verify-paystack')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'successful')
            ->assertJsonPath('purchase_status', 'active');

        $payload = [
            'event' => 'charge.success',
            'data' => ['reference' => 'HBS_PAYSTACK_SUCCESS'],
        ];
        $headers = ['X-Paystack-Signature' => 'valid-test-signature'];

        $this->postJson('/api/v1/webhooks/paystack', $payload, $headers)
            ->assertOk()->assertJsonPath('message', 'ok');
        $this->postJson('/api/v1/webhooks/paystack', $payload, $headers)
            ->assertOk()->assertJsonPath('message', 'ok');

        $purchase = Purchase::where('reference', 'HBS_PAYSTACK_SUCCESS')->firstOrFail();
        $payment = Payment::where('reference', 'HBS_PAYSTACK_SUCCESS')->firstOrFail();
        $this->assertSame('successful', $payment->status);
        $this->assertSame('card', $payment->channel);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('active', $purchase->status);
        $this->assertSame('paystack_verification', $purchase->verification_method);
        $this->assertNotNull($purchase->verified_at);
        Queue::assertPushed(ActivateHotspotUserJob::class, 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('purchases', 1);
    }

    public function test_customer_cannot_verify_another_customers_paystack_reference(): void
    {
        $owner = Customer::factory()->active()->create();
        $otherCustomer = Customer::factory()->active()->create();
        $router = Router::factory()->create(['connection_mode' => 'live']);
        $package = InternetPackage::factory()->create();
        $purchase = Purchase::create([
            'customer_id' => $owner->id,
            'package_id' => $package->id,
            'router_id' => $router->id,
            'subtotal' => 10,
            'payment_fee' => 0.20,
            'amount' => 10.20,
            'reference' => 'HBS_PRIVATE_REFERENCE',
            'payment_method' => 'paystack',
            'fulfillment_type' => 'live',
            'status' => 'pending',
        ]);
        Payment::create([
            'customer_id' => $owner->id,
            'purchase_id' => $purchase->id,
            'reference' => 'HBS_PRIVATE_REFERENCE',
            'amount' => 10.20,
            'currency' => 'GHS',
            'status' => 'pending',
            'provider' => 'paystack',
        ]);

        $paystack = \Mockery::mock(PaystackService::class);
        $paystack->shouldNotReceive('verifyTransaction');
        $this->app->instance(PaystackService::class, $paystack);

        $token = $otherCustomer->createToken('wrong-customer', ['customer'])->plainTextToken;
        $this->withToken($token)
            ->postJson('/api/v1/customer/purchases/HBS_PRIVATE_REFERENCE/verify-paystack')
            ->assertNotFound();

        $this->assertSame('pending', Payment::where('reference', 'HBS_PRIVATE_REFERENCE')->value('status'));
        $this->assertSame('pending', $purchase->fresh()->status);
    }

    public function test_paystack_amount_mismatch_never_activates_purchase(): void
    {
        Queue::fake();
        $customer = Customer::factory()->active()->create();
        $router = Router::factory()->create(['connection_mode' => 'live']);
        $package = InternetPackage::factory()->create();
        $purchase = Purchase::create([
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'router_id' => $router->id,
            'subtotal' => 10,
            'payment_fee' => 0.20,
            'amount' => 10.20,
            'reference' => 'HBS_AMOUNT_MISMATCH',
            'payment_method' => 'paystack',
            'fulfillment_type' => 'live',
            'status' => 'pending',
        ]);
        Payment::create([
            'customer_id' => $customer->id,
            'purchase_id' => $purchase->id,
            'reference' => 'HBS_AMOUNT_MISMATCH',
            'amount' => 10.20,
            'currency' => 'GHS',
            'status' => 'pending',
            'provider' => 'paystack',
        ]);

        $paystack = \Mockery::mock(PaystackService::class);
        $paystack->shouldReceive('verifyTransaction')->once()->andReturn([
            'success' => true,
            'data' => [
                'status' => 'success',
                'amount' => 100,
                'reference' => 'HBS_AMOUNT_MISMATCH',
            ],
        ]);
        $this->app->instance(PaystackService::class, $paystack);

        $token = $customer->createToken('amount-mismatch', ['customer'])->plainTextToken;
        $this->withToken($token)
            ->postJson('/api/v1/customer/purchases/HBS_AMOUNT_MISMATCH/verify-paystack')
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'failed');

        $this->assertSame('failed', Payment::where('reference', 'HBS_AMOUNT_MISMATCH')->value('status'));
        $this->assertSame('pending', $purchase->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_customer_cannot_bypass_router_payment_gateway_setting(): void
    {
        $customer = Customer::factory()->active()->create();
        $router = Router::factory()->create([
            'connection_mode' => 'live',
            'momo_enabled' => false,
            'paystack_enabled' => true,
        ]);
        $package = InternetPackage::factory()->create(['status' => 'active']);
        RouterPackageProfile::create([
            'router_id' => $router->id,
            'package_id' => $package->id,
            'profile_name' => 'router-gateway-test',
            'shared_users' => 1,
        ]);
        SystemSetting::set('momo_enabled', true);

        $token = $customer->createToken('router-gateway-test', ['customer'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/customer/purchases', [
            'package_id' => $package->id,
            'router_id' => $router->id,
            'payment_method' => 'momo',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.payment_method.0', 'This payment method is disabled for the selected router.');

        $this->assertDatabaseMissing('purchases', [
            'customer_id' => $customer->id,
            'router_id' => $router->id,
        ]);
    }

    private function makePurchase(Customer $customer, string $reference): Purchase
    {
        $router = Router::factory()->create(['connection_mode' => 'live']);
        $package = InternetPackage::factory()->create();

        return Purchase::create([
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'router_id' => $router->id,
            'subtotal' => 10,
            'payment_fee' => 0,
            'amount' => 10,
            'reference' => $reference,
            'payment_method' => 'momo',
            'fulfillment_type' => 'live',
            'status' => 'queued',
        ]);
    }
}
