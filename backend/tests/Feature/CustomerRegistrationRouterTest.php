<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerRegistrationRouterTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_registration_persists_router_before_first_purchase(): void
    {
        $router = Router::factory()->create([
            'name' => 'Royal Hotspot Tarkwa',
            'location' => 'Tarkwa',
        ]);

        $response = $this->postJson('/api/v1/customer/register', [
            'full_name' => 'New Portal Customer',
            'phone' => '0240000001',
            'email' => 'portal-customer@example.test',
            'username' => 'portal-customer',
            'password' => 'secure-password',
            'router_id' => $router->id,
        ])->assertCreated();

        $customerId = $response->json('customer.id');
        $this->assertDatabaseHas('customers', [
            'id' => $customerId,
            'home_router_id' => $router->id,
            'status' => 'inactive',
        ]);

        $admin = User::factory()->superAdmin()->create();
        $token = $admin->createToken('router-report', ['admin'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/admin/reports/customers')
            ->assertOk()
            ->assertJsonPath('customers.data.0.home_router.name', 'Royal Hotspot Tarkwa')
            ->assertJsonPath('customers.data.0.home_router.location', 'Tarkwa');
    }

    public function test_registration_without_portal_context_remains_unassigned(): void
    {
        $this->postJson('/api/v1/customer/register', [
            'full_name' => 'Direct Registration',
            'phone' => '0240000002',
            'email' => 'direct-customer@example.test',
            'username' => 'direct-customer',
            'password' => 'secure-password',
        ])->assertCreated();

        $this->assertDatabaseHas('customers', [
            'username' => 'direct-customer',
            'home_router_id' => null,
        ]);
    }

    public function test_authorized_admin_can_edit_customer_and_assign_home_router(): void
    {
        $router = Router::factory()->create(['name' => 'Assigned Router']);
        $customer = Customer::factory()->create(['home_router_id' => null]);
        $admin = User::factory()->superAdmin()->create();
        $token = $admin->createToken('customer-editor', ['admin'])->plainTextToken;

        $this->withToken($token)->patchJson("/api/v1/admin/customers/{$customer->id}", [
            'full_name' => 'Edited Customer',
            'phone' => '0249999000',
            'email' => 'edited@example.test',
            'username' => 'edited-customer',
            'home_router_id' => $router->id,
        ])->assertOk()
            ->assertJsonPath('full_name', 'Edited Customer')
            ->assertJsonPath('home_router.id', $router->id)
            ->assertJsonPath('assignable_routers.0.id', $router->id);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'username' => 'edited-customer',
            'home_router_id' => $router->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'customer.updated',
        ]);
    }

    public function test_customer_details_open_with_current_purchase_history_shape(): void
    {
        $router = Router::factory()->create(['name' => 'Customer Router']);
        $customer = Customer::factory()->create(['home_router_id' => $router->id]);
        $admin = User::factory()->superAdmin()->create();
        $token = $admin->createToken('customer-viewer', ['admin'])->plainTextToken;

        $this->withToken($token)->getJson("/api/v1/admin/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('id', $customer->id)
            ->assertJsonPath('home_router.id', $router->id)
            ->assertJsonPath('subscriptions', [])
            ->assertJsonPath('assignable_routers.0.id', $router->id);
    }

    public function test_router_scoped_admin_cannot_assign_customer_to_another_router(): void
    {
        $allowedRouter = Router::factory()->create();
        $otherRouter = Router::factory()->create();
        $customer = Customer::factory()->create([
            'home_router_id' => $allowedRouter->id,
            'username' => 'scoped-customer',
        ]);
        $admin = User::factory()->admin()->create(['permissions' => ['customers.view', 'customers.manage']]);
        $admin->routers()->attach($allowedRouter);
        $token = $admin->createToken('scoped-customer-editor', ['admin'])->plainTextToken;

        $this->withToken($token)->patchJson("/api/v1/admin/customers/{$customer->id}", [
            'full_name' => $customer->full_name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'username' => $customer->username,
            'home_router_id' => $otherRouter->id,
        ])->assertForbidden();

        $this->assertSame($allowedRouter->id, $customer->fresh()->home_router_id);
    }

    public function test_router_scoped_customer_report_includes_assigned_customer_before_purchase(): void
    {
        $router = Router::factory()->create();
        $customer = Customer::factory()->create(['home_router_id' => $router->id]);
        $admin = User::factory()->admin()->create(['permissions' => ['customers.view']]);
        $admin->routers()->attach($router);
        $token = $admin->createToken('scoped-customer-viewer', ['admin'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/reports/customers')
            ->assertOk()
            ->assertJsonPath('customers.data.0.id', $customer->id)
            ->assertJsonPath('customers.data.0.home_router.id', $router->id);
    }
}
