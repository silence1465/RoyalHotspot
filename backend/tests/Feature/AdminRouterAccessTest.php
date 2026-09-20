<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HotspotUser;
use App\Models\InternetPackage;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Router;
use App\Models\RouterPackageProfile;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\MikrotikService;
use App\Services\MikrotikServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

class AdminRouterAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_confirm_router_changing_terminal_commands(): void
    {
        $router = Router::factory()->create(['connection_mode' => 'live']);
        $admin = User::factory()->admin()->create(['permissions' => ['routers.manage']]);
        $admin->routers()->attach($router);
        $adminToken = $admin->createToken('terminal-admin', ['admin'])->plainTextToken;
        $super = User::factory()->superAdmin()->create();
        $superToken = $super->createToken('terminal-super', ['admin'])->plainTextToken;
        config(['mikrotik_security.key_hash' => Hash::make('dummy-secure-key')]);

        $this->withToken($adminToken)->postJson('/api/v1/admin/mikrotik-security/unlock', [
            'security_key' => 'dummy-secure-key',
        ])->assertOk();
        $this->withToken($adminToken)->postJson("/api/v1/admin/router-management/{$router->id}/terminal", [
            'command' => '/system identity set name=test-router',
            'confirmation' => 'EXECUTE',
        ])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($superToken)->postJson('/api/v1/admin/mikrotik-security/unlock', [
            'security_key' => 'dummy-secure-key',
        ])->assertOk();
        $this->withToken($superToken)->postJson("/api/v1/admin/router-management/{$router->id}/terminal", [
            'command' => '/system identity set name=test-router',
        ])->assertUnprocessable();
    }

    public function test_super_admin_permanently_deletes_only_inactive_selected_test_customer_data(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $token = $admin->createToken('cleanup-super', ['admin'])->plainTextToken;
        $router = Router::factory()->create([
            'connection_mode' => 'live',
            'name' => 'Simulated Test Router',
            'location' => 'Test Location',
        ]);
        $package = InternetPackage::factory()->create();
        $customer = Customer::factory()->create(['status' => 'inactive']);
        $purchase = Purchase::create([
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'router_id' => $router->id,
            'subtotal' => 5,
            'payment_fee' => 0,
            'amount' => 5,
            'reference' => 'RW-DELETE-TEST',
            'payment_method' => 'paystack',
            'fulfillment_type' => 'live',
            'status' => 'expired',
        ]);
        $customer->update(['current_purchase_id' => $purchase->id]);
        $hotspotUser = HotspotUser::create([
            'customer_id' => $customer->id,
            'router_id' => $router->id,
            'username' => $customer->username,
            'password' => 'test-password',
            'mikrotik_user_id' => '*TEST',
            'profile' => 'test-profile',
            'disabled' => true,
        ]);
        Payment::create([
            'customer_id' => $customer->id,
            'purchase_id' => $purchase->id,
            'reference' => 'HBS-DELETE-TEST',
            'amount' => 5,
            'currency' => 'GHS',
            'status' => 'successful',
            'provider' => 'paystack',
        ]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('disableAndDisconnectHotspotUser')
            ->once()->with($hotspotUser->username, '*TEST')->andReturn(['success' => true]);
        $mikrotik->shouldReceive('removeHotspotUser')
            ->once()->with('*TEST')->andReturn(['success' => true]);
        $factory = Mockery::mock(MikrotikServiceFactory::class);
        $factory->shouldReceive('make')->once()->with(Mockery::on(fn ($value) => $value->is($router)))->andReturn($mikrotik);
        $this->app->instance(MikrotikServiceFactory::class, $factory);

        $this->withToken($token)->getJson('/api/v1/admin/reports/customers')
            ->assertOk()
            ->assertJsonPath('customers.data.0.current_purchase.router.name', 'Simulated Test Router')
            ->assertJsonPath('customers.data.0.current_purchase.router.location', 'Test Location');

        $this->withToken($token)->deleteJson("/api/v1/admin/customers/{$customer->id}/test-data", [
            'confirmation' => 'DELETE TEST DATA',
        ])->assertOk()->assertJsonPath('deleted.purchases', 1);

        $this->assertNull(Customer::withTrashed()->find($customer->id));
        $this->assertDatabaseMissing('purchases', ['id' => $purchase->id]);
        $this->assertDatabaseMissing('payments', ['reference' => 'HBS-DELETE-TEST']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.test_data_deleted', 'user_id' => $admin->id]);
    }

    public function test_admin_can_select_only_assigned_routers_and_combined_scope_is_filtered(): void
    {
        [$first, $second, $forbidden] = Router::factory()->count(3)->create();
        $package = InternetPackage::factory()->create();
        $admin = User::factory()->admin()->create([
            'permissions' => ['dashboard.view', 'customers.view'],
        ]);
        $admin->routers()->attach([$first->id, $second->id]);
        $token = $admin->createToken('scoped-admin', ['admin'])->plainTextToken;

        foreach ([[$first, 'scope-one'], [$second, 'scope-two'], [$forbidden, 'scope-secret']] as [$router, $username]) {
            $customer = Customer::factory()->active()->create(['username' => $username]);
            Purchase::create([
                'customer_id' => $customer->id,
                'package_id' => $package->id,
                'router_id' => $router->id,
                'subtotal' => 10,
                'payment_fee' => 0,
                'amount' => 10,
                'reference' => 'RW-'.strtoupper(str_replace('-', '', $username)),
                'payment_method' => 'momo',
                'fulfillment_type' => 'live',
                'status' => 'active',
                'verified_at' => now(),
                'policy_access_status' => 'active',
            ]);
        }

        $this->withToken($token)->withHeader('X-Router-Id', 'all')
            ->getJson('/api/v1/admin/dashboard/stats')
            ->assertOk()->assertJsonPath('total_customers', 2);

        $this->withToken($token)->withHeader('X-Router-Id', (string) $first->id)
            ->getJson('/api/v1/admin/customers')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.username', 'scope-one');

        $this->withToken($token)->withHeader('X-Router-Id', (string) $forbidden->id)
            ->getJson('/api/v1/admin/customers')->assertForbidden();
    }

    public function test_paystack_only_permission_hides_momo_transactions(): void
    {
        $router = Router::factory()->create();
        $package = InternetPackage::factory()->create();
        $customer = Customer::factory()->active()->create();
        $admin = User::factory()->admin()->create([
            'permissions' => ['transactions.paystack.view', 'reports.view'],
        ]);
        $admin->routers()->attach($router);
        $token = $admin->createToken('paystack-admin', ['admin'])->plainTextToken;

        foreach (['paystack', 'momo'] as $method) {
            Purchase::create([
                'customer_id' => $customer->id, 'package_id' => $package->id, 'router_id' => $router->id,
                'subtotal' => 20, 'payment_fee' => 0, 'amount' => 20,
                'reference' => 'RW-'.strtoupper($method), 'payment_method' => $method,
                'fulfillment_type' => 'live', 'status' => 'active', 'verified_at' => now(),
                'policy_access_status' => 'active',
            ]);
        }

        $this->withToken($token)->getJson('/api/v1/admin/reports/accounting?year='.now()->year)
            ->assertOk()
            ->assertJsonPath('summary.transaction_count', 1)
            ->assertJsonPath('entries.data.0.payment_method', 'paystack');

        $this->withToken($token)->getJson('/api/v1/admin/purchases')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payment_method', 'paystack');

        $momo = Purchase::where('payment_method', 'momo')->firstOrFail();
        $this->withToken($token)->getJson("/api/v1/admin/purchases/{$momo->id}")
            ->assertForbidden();
    }

    public function test_router_selector_filters_routers_packages_and_direct_purchase_access(): void
    {
        [$assigned, $forbidden] = Router::factory()->count(2)->create();
        [$assignedPackage, $forbiddenPackage] = InternetPackage::factory()->count(2)->create();
        RouterPackageProfile::create(['router_id' => $assigned->id, 'package_id' => $assignedPackage->id, 'profile_name' => 'assigned']);
        RouterPackageProfile::create(['router_id' => $forbidden->id, 'package_id' => $forbiddenPackage->id, 'profile_name' => 'forbidden']);

        $customer = Customer::factory()->active()->create();
        $secretPurchase = Purchase::create([
            'customer_id' => $customer->id, 'package_id' => $forbiddenPackage->id, 'router_id' => $forbidden->id,
            'subtotal' => 20, 'payment_fee' => 0, 'amount' => 20, 'reference' => 'RW-FORBIDDEN-DIRECT',
            'payment_method' => 'paystack', 'fulfillment_type' => 'live', 'status' => 'active',
            'verified_at' => now(), 'policy_access_status' => 'active',
        ]);

        $admin = User::factory()->admin()->create([
            'permissions' => ['routers.view', 'packages.view', 'transactions.paystack.view'],
        ]);
        $admin->routers()->attach($assigned);
        $token = $admin->createToken('single-router-admin', ['admin'])->plainTextToken;
        config(['mikrotik_security.key_hash' => Hash::make('dummy-secure-key')]);
        $this->withToken($token)->postJson('/api/v1/admin/mikrotik-security/unlock', [
            'security_key' => 'dummy-secure-key',
        ])->assertOk();

        $this->withToken($token)->getJson('/api/v1/admin/routers')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $assigned->id);
        $this->withToken($token)->getJson('/api/v1/admin/packages')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $assignedPackage->id);
        $this->withToken($token)->getJson("/api/v1/admin/packages/{$forbiddenPackage->id}")
            ->assertForbidden();
        $this->withToken($token)->getJson("/api/v1/admin/purchases/{$secretPurchase->id}")
            ->assertForbidden();
    }

    public function test_permission_denials_are_enforced_for_dummy_admin(): void
    {
        $router = Router::factory()->create();
        $admin = User::factory()->admin()->create(['permissions' => ['customers.view']]);
        $admin->routers()->attach($router);
        $token = $admin->createToken('customers-only-admin', ['admin'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/customers')->assertOk();
        $this->withToken($token)->getJson('/api/v1/admin/dashboard/stats')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/purchases')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/routers')->assertForbidden();
    }

    public function test_super_admin_can_create_admin_with_multiple_routers(): void
    {
        $super = User::factory()->superAdmin()->create();
        $routers = Router::factory()->count(2)->create();
        $token = $super->createToken('super-admin', ['admin'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/admin/admin-users', [
            'name' => 'Two Router Admin', 'email' => 'two-router@example.test', 'password' => 'safe-password',
            'role' => 'admin', 'status' => 'active', 'router_ids' => $routers->pluck('id')->all(),
            'permissions' => ['dashboard.view', 'customers.view', 'bandwidth.view'],
        ])->assertCreated();

        $this->assertCount(2, $response->json('routers'));
        $this->assertDatabaseHas('users', ['email' => 'two-router@example.test']);
        $this->assertDatabaseCount('admin_router', 2);
    }

    public function test_admin_can_quickly_change_the_operating_mode(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $token = $admin->createToken('mode-admin', ['admin'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/operating-mode')
            ->assertOk()
            ->assertJsonPath('mode', 'normal');

        $this->withToken($token)->putJson('/api/v1/admin/operating-mode', ['mode' => 'data_cap'])
            ->assertUnprocessable();

        $this->withToken($token)->putJson('/api/v1/admin/operating-mode', ['mode' => 'normal'])
            ->assertOk()
            ->assertJsonPath('mode', 'normal');

        $this->assertSame('normal', SystemSetting::get('operating_mode'));
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'settings.operating_mode_updated',
        ]);

        $this->withToken($token)->putJson('/api/v1/admin/operating-mode', ['mode' => 'unsupported'])
            ->assertUnprocessable();
    }

    public function test_restricted_admin_cannot_change_global_mode_or_read_global_settings(): void
    {
        $admin = User::factory()->admin()->create(['permissions' => ['customers.view']]);
        $token = $admin->createToken('restricted-mode', ['admin'])->plainTextToken;
        $this->withToken($token)->putJson('/api/v1/admin/operating-mode', ['mode' => 'normal'])->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/settings')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/notifications/summary')
            ->assertOk()->assertJsonCount(0, 'recent')->assertJsonCount(0, 'navigation_counts');
    }

    public function test_deactivated_admin_cannot_reuse_a_token(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'inactive']);
        $token = $admin->createToken('inactive-admin', ['admin'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/admin/me')->assertForbidden();
    }

    public function test_global_search_does_not_reveal_unassigned_customers(): void
    {
        [$allowed, $other] = Router::factory()->count(2)->create();
        $package = InternetPackage::factory()->create();
        foreach ([$allowed, $other] as $router) {
            $customer = Customer::factory()->active()->create(['full_name' => 'Search Dummy '.$router->id]);
            Purchase::create([
                'customer_id' => $customer->id, 'router_id' => $router->id, 'package_id' => $package->id,
                'amount' => 10, 'reference' => 'RW-SEARCH-'.$router->id,
                'status' => 'active', 'payment_method' => 'momo', 'fulfillment_type' => 'live',
            ]);
        }
        $admin = User::factory()->admin()->create(['permissions' => ['customers.view']]);
        $admin->routers()->attach($allowed);
        $token = $admin->createToken('search-test', ['admin'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/admin/search?q=Search')
            ->assertOk()->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.full_name', 'Search Dummy '.$allowed->id)
            ->assertJsonCount(0, 'purchases')->assertJsonCount(0, 'vouchers');
    }

    public function test_router_isp_topology_can_be_managed_from_router_form(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $token = $admin->createToken('topology-admin', ['admin'])->plainTextToken;
        config(['mikrotik_security.key_hash' => Hash::make('dummy-secure-key')]);

        $this->withToken($token)->postJson('/api/v1/admin/mikrotik-security/unlock', [
            'security_key' => 'dummy-secure-key',
        ])->assertOk();

        $response = $this->withToken($token)->postJson('/api/v1/admin/routers', [
            'name' => 'Production CCR',
            'location' => 'Tarkwa',
            'connection_mode' => 'live',
            'wireguard_ip' => '10.30.0.2',
            'api_username' => 'api-admin',
            'api_password' => 'safe-router-password',
            'api_port' => 8729,
            'api_ssl' => true,
            'routeros_version' => '7.18.2',
            'isp_failover_enabled' => true,
            'isp_failback_enabled' => true,
            'isps' => [[
                'name' => 'Primary Fiber',
                'wan_interface' => 'ether1',
                'gateway' => '192.168.10.1',
                'routing_table' => 'to-primary',
                'monthly_capacity_gb' => 3000,
                'subscriber_limit' => 200,
                'priority' => 10,
                'enabled' => true,
            ]],
        ])->assertCreated()
            ->assertJsonPath('routeros_version', '7.18.2')
            ->assertJsonPath('isp_failover_enabled', true)
            ->assertJsonPath('isp_failback_enabled', true)
            ->assertJsonPath('isps.0.name', 'Primary Fiber')
            ->assertJsonPath('isps.0.monthly_capacity_bytes', 3221225472000);

        $routerId = $response->json('id');
        $ispId = $response->json('isps.0.id');

        $this->withToken($token)->putJson("/api/v1/admin/routers/{$routerId}", [
            'name' => 'Production CCR',
            'location' => 'Tarkwa',
            'connection_mode' => 'live',
            'wireguard_ip' => '10.30.0.2',
            'api_username' => 'api-admin',
            'api_port' => 8729,
            'api_ssl' => true,
            'routeros_version' => '7.18.2',
            'isp_failover_enabled' => false,
            'isp_failback_enabled' => true,
            'isps' => [[
                'id' => $ispId,
                'name' => 'Primary Fiber',
                'wan_interface' => 'ether1',
                'gateway' => '192.168.10.1',
                'routing_table' => 'to-primary',
                'monthly_capacity_gb' => 3500,
                'subscriber_limit' => 250,
                'priority' => 10,
                'enabled' => true,
            ], [
                'name' => 'Backup LTE',
                'wan_interface' => 'ether2',
                'gateway' => '192.168.20.1',
                'routing_table' => 'to-backup',
                'monthly_capacity_gb' => 500,
                'subscriber_limit' => 50,
                'priority' => 20,
                'enabled' => false,
            ]],
        ])->assertOk()
            ->assertJsonCount(2, 'isps')
            ->assertJsonPath('isp_failover_enabled', false)
            ->assertJsonPath('isps.0.subscriber_limit', 250)
            ->assertJsonPath('isps.1.enabled', false);

        $this->assertDatabaseHas('router_isps', [
            'router_id' => $routerId,
            'name' => 'Primary Fiber',
            'monthly_capacity_bytes' => 3758096384000,
        ]);
    }

    public function test_authorized_admin_can_reset_router_wifi_password_and_receives_it_once(): void
    {
        $router = Router::factory()->create();
        $customer = Customer::factory()->active()->create();
        $hotspotUser = HotspotUser::create([
            'customer_id' => $customer->id,
            'router_id' => $router->id,
            'mikrotik_user_id' => '*OLD',
            'username' => $customer->username,
            'password' => 'old-password',
            'profile' => '1-week',
            'disabled' => false,
        ]);
        $admin = User::factory()->admin()->create(['permissions' => ['customers.view', 'customers.manage']]);
        $admin->routers()->attach($router);
        $token = $admin->createToken('password-reset-admin', ['admin'])->plainTextToken;
        config(['mikrotik_security.key_hash' => Hash::make('dummy-secure-key')]);
        $this->withToken($token)->postJson('/api/v1/admin/mikrotik-security/unlock', [
            'security_key' => 'dummy-secure-key',
        ])->assertOk();

        $generatedPassword = null;
        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('resetHotspotUserPassword')->once()
            ->with($customer->username, Mockery::on(function ($value) use (&$generatedPassword) {
                $generatedPassword = $value;

                return is_string($value) && strlen($value) >= 8;
            }))
            ->andReturn(['success' => true, 'data' => [
                'mikrotik_user_id' => '*CURRENT', 'disconnected_sessions' => 1, 'removed_cookies' => 1,
            ]]);
        $factory = Mockery::mock(MikrotikServiceFactory::class);
        $factory->shouldReceive('make')->once()->withArgs(fn ($value) => $value->is($router))->andReturn($mikrotik);
        $this->app->instance(MikrotikServiceFactory::class, $factory);

        $response = $this->withToken($token)
            ->postJson("/api/v1/admin/customers/{$customer->id}/hotspot-users/{$hotspotUser->id}/reset-password")
            ->assertOk()
            ->assertJsonPath('username', $customer->username)
            ->assertJsonPath('temporary_password', fn ($value) => $value === $generatedPassword);

        $this->assertSame($response->json('temporary_password'), $hotspotUser->fresh()->makeVisible('password')->password);
        $this->assertSame('*CURRENT', $hotspotUser->fresh()->mikrotik_user_id);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'customer.hotspot_password_reset',
        ]);
    }

    public function test_wifi_password_reset_requires_manage_permission(): void
    {
        $router = Router::factory()->create();
        $customer = Customer::factory()->active()->create();
        $hotspotUser = HotspotUser::create([
            'customer_id' => $customer->id, 'router_id' => $router->id,
            'username' => $customer->username, 'password' => 'unchanged-password',
            'profile' => '1-week', 'disabled' => false,
        ]);
        $admin = User::factory()->admin()->create(['permissions' => ['customers.view']]);
        $admin->routers()->attach($router);
        $token = $admin->createToken('customer-viewer', ['admin'])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/admin/customers/{$customer->id}/hotspot-users/{$hotspotUser->id}/reset-password")
            ->assertForbidden();
        $this->assertSame('unchanged-password', $hotspotUser->fresh()->makeVisible('password')->password);
    }
}
