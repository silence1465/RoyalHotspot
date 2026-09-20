<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ActiveUsersController;
use App\Http\Controllers\Admin\FreeTrialCampaignController;
use App\Http\Controllers\Admin\PackageController as AdminPackageController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Customer\PackageController;
use App\Http\Controllers\Customer\PurchaseController;
use App\Models\Customer;
use App\Models\FreeTrialCampaign;
use App\Models\HotspotUser;
use App\Models\InternetPackage;
use App\Models\Purchase;
use App\Models\Router;
use App\Models\RouterPackageProfile;
use App\Models\SystemSetting;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class OperationalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_momo_bonus_can_be_configured_in_hours(): void
    {
        $package = InternetPackage::factory()->create([
            'momo_bonus_value' => 3,
            'momo_bonus_unit' => 'hours',
        ]);

        $this->assertSame(180, $package->momoBonusMinutes());

        $package->update(['momo_bonus_value' => 2, 'momo_bonus_unit' => 'days']);
        $this->assertSame(2880, $package->fresh()->momoBonusMinutes());
    }

    public function test_disabling_free_trial_expires_active_claims(): void
    {
        $router = Router::factory()->create(['connection_mode' => 'live']);
        $package = InternetPackage::factory()->create();
        RouterPackageProfile::create([
            'router_id' => $router->id,
            'package_id' => $package->id,
            'profile_name' => 'free-trial-profile',
        ]);
        $campaign = FreeTrialCampaign::create([
            'name' => 'Temporary free access',
            'package_id' => $package->id,
            'router_id' => $router->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $customer = Customer::factory()->active()->create();
        $purchase = Purchase::create([
            'customer_id' => $customer->id,
            'free_trial_campaign_id' => $campaign->id,
            'package_id' => $package->id,
            'router_id' => $router->id,
            'subtotal' => 0,
            'payment_fee' => 0,
            'amount' => 0,
            'reference' => 'RW-FREE01',
            'payment_method' => 'free_trial',
            'fulfillment_type' => 'live',
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => $campaign->ends_at,
        ]);

        $request = Request::create('/api/v1/admin/free-trials/'.$campaign->id, 'PUT', [
            'name' => $campaign->name,
            'package_id' => $package->id,
            'router_id' => $router->id,
            'starts_at' => $campaign->starts_at->toIso8601String(),
            'ends_at' => $campaign->ends_at->toIso8601String(),
            'is_active' => false,
        ]);
        $response = app(FreeTrialCampaignController::class)
            ->update($request, $campaign, app(PurchaseService::class));

        $this->assertSame(1, $response->getData(true)['revoked_claims']);
        $this->assertSame('expired', $purchase->fresh()->status);
        $this->assertSame('inactive', $customer->fresh()->status);
    }

    public function test_checkout_reports_enabled_gateways_and_rejects_a_disabled_one(): void
    {
        $package = InternetPackage::factory()->create(['status' => 'active']);
        $router = Router::factory()->create();
        RouterPackageProfile::create([
            'router_id' => $router->id,
            'package_id' => $package->id,
            'profile_name' => 'gateway-test',
        ]);
        $customer = Customer::factory()->create(['home_router_id' => $router->id]);
        SystemSetting::set('paystack_enabled', true);
        SystemSetting::set('momo_enabled', false);

        $packageRequest = Request::create('/api/v1/customer/packages');
        $packageRequest->setUserResolver(fn () => $customer);
        $payload = app(PackageController::class)->index($packageRequest)->getData(true);

        $this->assertTrue($payload['payment_methods']['paystack']);
        $this->assertFalse($payload['payment_methods']['momo']);
        $this->assertCount(1, $payload['packages']);

        $request = Request::create('/api/customer/purchases', 'POST', ['payment_method' => 'momo']);
        $response = app(PurchaseController::class)->store($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('unavailable', $response->getData(true)['message']);
    }

    public function test_active_router_session_includes_package_dates_and_live_data_usage(): void
    {
        $router = Router::factory()->create(['connection_mode' => 'live']);
        $customer = Customer::factory()->active()->create(['username' => 'dummy-user']);
        $package = InternetPackage::factory()->create(['name' => 'Dummy 6 Hours']);
        $startedAt = now()->subMinutes(20)->startOfSecond();
        $expiresAt = now()->addHours(5)->addMinutes(40)->startOfSecond();

        HotspotUser::create([
            'customer_id' => $customer->id,
            'router_id' => $router->id,
            'username' => 'dummy-user',
            'password' => 'dummy-hotspot-password',
            'profile' => 'dummy-profile',
        ]);

        Purchase::create([
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'router_id' => $router->id,
            'subtotal' => 10,
            'payment_fee' => 0,
            'amount' => 10,
            'reference' => 'RW-DUMMY1',
            'payment_method' => 'momo',
            'fulfillment_type' => 'live',
            'status' => 'active',
            'starts_at' => $startedAt,
            'expires_at' => $expiresAt,
        ]);

        $controller = new class extends ActiveUsersController
        {
            protected function activeUsersFor(Router $router): array
            {
                return [
                    'success' => true,
                    'data' => [[
                        '.id' => '*DUMMY',
                        'user' => 'dummy-user',
                        'address' => '10.5.5.20',
                        'uptime' => '20m',
                        'bytes-in' => 1048576,
                        'bytes-out' => 2097152,
                    ]],
                ];
            }
        };

        $session = $controller->index(Request::create('/api/admin/active-users'))->getData(true)['sessions'][0];

        $this->assertSame('Dummy 6 Hours', $session['package_name']);
        $this->assertSame($startedAt->toIso8601String(), $session['started_at']);
        $this->assertSame($expiresAt->toIso8601String(), $session['expires_at']);
        $this->assertSame(1048576, $session['bytes_in']);
        $this->assertSame(2097152, $session['bytes_out']);
        $this->assertSame(3145728, $session['bytes_in'] + $session['bytes_out']);
    }

    public function test_package_with_historical_purchase_returns_clear_delete_message(): void
    {
        $customer = Customer::factory()->active()->create();
        $router = Router::factory()->create();
        $package = InternetPackage::factory()->create();
        Purchase::create([
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'router_id' => $router->id,
            'subtotal' => 10,
            'payment_fee' => 0,
            'amount' => 10,
            'reference' => 'RW-HISTORY',
            'payment_method' => 'momo',
            'fulfillment_type' => 'live',
            'status' => 'expired',
        ]);

        $response = app(AdminPackageController::class)
            ->destroy(Request::create('/api/v1/admin/packages/'.$package->id, 'DELETE'), $package);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('1 purchase', $response->getData(true)['message']);
        $this->assertStringContainsString('Inactive', $response->getData(true)['message']);
        $this->assertDatabaseHas('internet_packages', ['id' => $package->id]);
    }

    public function test_accounting_history_filters_by_year_month_and_router(): void
    {
        $customer = Customer::factory()->active()->create();
        $package = InternetPackage::factory()->create();
        $mainRouter = Router::factory()->create(['name' => 'Main Router']);
        $otherRouter = Router::factory()->create(['name' => 'Other Router']);

        $rows = [
            [$mainRouter, 'RW-JAN001', 'momo', 10, 0, '2026-01-15 10:00:00'],
            [$mainRouter, 'RW-FEB001', 'paystack', 20, 0.40, '2026-02-10 11:00:00'],
            [$otherRouter, 'RW-FEB002', 'momo', 30, 0, '2026-02-12 12:00:00'],
        ];

        foreach ($rows as [$router, $reference, $method, $subtotal, $fee, $verifiedAt]) {
            Purchase::create([
                'customer_id' => $customer->id,
                'package_id' => $package->id,
                'router_id' => $router->id,
                'subtotal' => $subtotal,
                'payment_fee' => $fee,
                'amount' => $subtotal + $fee,
                'reference' => $reference,
                'payment_method' => $method,
                'fulfillment_type' => 'live',
                'status' => 'active',
                'verified_at' => $verifiedAt,
            ]);
        }

        $request = Request::create('/api/v1/admin/reports/accounting', 'GET', [
            'year' => 2026,
            'month' => 2,
            'router_id' => $mainRouter->id,
        ]);
        $data = app(ReportController::class)->accounting($request)->getData(true);

        $this->assertSame(1, $data['summary']['transaction_count']);
        $this->assertEquals(20.0, $data['summary']['subtotal']);
        $this->assertEquals(0.4, $data['summary']['fees']);
        $this->assertEquals(20.4, $data['summary']['total']);
        $this->assertCount(1, $data['entries']['data']);
        $this->assertSame('RW-FEB001', $data['entries']['data'][0]['reference']);
        $this->assertSame('Main Router', $data['entries']['data'][0]['router']['name']);
    }
}
