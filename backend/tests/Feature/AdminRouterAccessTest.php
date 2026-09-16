<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InternetPackage;
use App\Models\Purchase;
use App\Models\Router;
use App\Models\RouterPackageProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminRouterAccessTest extends TestCase
{
    use RefreshDatabase;

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
}
