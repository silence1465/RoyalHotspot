<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FreeTrialCampaign;
use App\Models\InternetPackage;
use App\Models\Purchase;
use App\Models\Router;
use App\Models\RouterPackageProfile;
use App\Models\SystemSetting;
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

    public function test_customer_sees_and_can_buy_only_packages_mapped_to_home_router(): void
    {
        $homeRouter = Router::factory()->create(['name' => 'Home Router', 'momo_enabled' => true]);
        $otherRouter = Router::factory()->create(['name' => 'Other Router', 'momo_enabled' => true]);
        $homePackage = InternetPackage::factory()->create(['name' => 'Home Package', 'status' => 'active']);
        $otherPackage = InternetPackage::factory()->create(['name' => 'Other Package', 'status' => 'active']);
        RouterPackageProfile::create(['router_id' => $homeRouter->id, 'package_id' => $homePackage->id, 'profile_name' => 'home-profile']);
        RouterPackageProfile::create(['router_id' => $otherRouter->id, 'package_id' => $otherPackage->id, 'profile_name' => 'other-profile']);
        $customer = Customer::factory()->create(['home_router_id' => $homeRouter->id]);
        $token = $customer->createToken('customer-packages', ['customer'])->plainTextToken;
        SystemSetting::set('momo_enabled', true);

        $this->withToken($token)->getJson('/api/v1/customer/packages')
            ->assertOk()
            ->assertJsonPath('assigned_router.id', $homeRouter->id)
            ->assertJsonCount(1, 'packages')
            ->assertJsonPath('packages.0.id', $homePackage->id)
            ->assertJsonPath('packages.0.available_routers.0.id', $homeRouter->id);

        $successfulPurchase = $this->withToken($token)->postJson('/api/v1/customer/purchases', [
            'package_id' => $homePackage->id,
            'router_id' => $homeRouter->id,
            'payment_method' => 'momo',
        ])->assertCreated()
            ->assertJsonPath('router.id', $homeRouter->id)
            ->assertJsonPath('package.name', 'Home Package');

        $this->assertDatabaseHas('purchases', [
            'reference' => $successfulPurchase->json('reference'),
            'customer_id' => $customer->id,
            'package_id' => $homePackage->id,
            'router_id' => $homeRouter->id,
            'status' => 'pending',
        ]);

        $this->withToken($token)->postJson('/api/v1/customer/purchases', [
            'package_id' => $otherPackage->id,
            'router_id' => $otherRouter->id,
            'payment_method' => 'momo',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('router_id');

        $this->assertDatabaseMissing('purchases', [
            'customer_id' => $customer->id,
            'router_id' => $otherRouter->id,
        ]);
    }

    public function test_unassigned_customer_receives_no_packages(): void
    {
        $customer = Customer::factory()->create(['home_router_id' => null]);
        $token = $customer->createToken('unassigned-customer', ['customer'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/customer/packages')
            ->assertOk()
            ->assertJsonPath('assigned_router', null)
            ->assertJsonPath('packages', [])
            ->assertJsonPath('message', 'No router is assigned to your account. Please contact the administrator.');
    }

    public function test_customer_sees_only_active_free_campaign_for_home_router(): void
    {
        $homeRouter = Router::factory()->create();
        $otherRouter = Router::factory()->create();
        $package = InternetPackage::factory()->create(['status' => 'active']);
        RouterPackageProfile::create(['router_id' => $homeRouter->id, 'package_id' => $package->id, 'profile_name' => 'free-home']);
        RouterPackageProfile::create(['router_id' => $otherRouter->id, 'package_id' => $package->id, 'profile_name' => 'free-other']);
        $campaign = FreeTrialCampaign::create([
            'name' => 'Home Router Free Day',
            'package_id' => $package->id,
            'router_id' => $homeRouter->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $otherCampaign = FreeTrialCampaign::create([
            'name' => 'Other Router Campaign',
            'package_id' => $package->id,
            'router_id' => $otherRouter->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        $customer = Customer::factory()->create(['home_router_id' => $homeRouter->id]);
        $token = $customer->createToken('free-campaign-home', ['customer'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/customer/free-trial')
            ->assertOk()
            ->assertJsonPath('campaign.id', $campaign->id)
            ->assertJsonPath('campaign.name', 'Home Router Free Day')
            ->assertJsonPath('claimed', false)
            ->assertJsonPath('can_claim', true);

        $this->withToken($token)->postJson('/api/v1/customer/free-trial/claim', [
            'campaign_id' => $otherCampaign->id,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'This free campaign is not available at your assigned router.');

    }

    public function test_active_package_hides_free_campaign_claim_action(): void
    {
        $router = Router::factory()->create();
        $package = InternetPackage::factory()->create(['status' => 'active']);
        RouterPackageProfile::create(['router_id' => $router->id, 'package_id' => $package->id, 'profile_name' => 'active-access']);
        $campaign = FreeTrialCampaign::create([
            'name' => 'Later Free Campaign',
            'package_id' => $package->id,
            'router_id' => $router->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['home_router_id' => $router->id]);
        Purchase::create([
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'router_id' => $router->id,
            'subtotal' => 10,
            'payment_fee' => 0,
            'amount' => 10,
            'reference' => 'ACTIVE-BLOCKS-FREE',
            'payment_method' => 'momo',
            'fulfillment_type' => 'live',
            'status' => 'active',
            'verified_at' => now(),
            'starts_at' => now(),
            'expires_at' => now()->addHour(),
            'usage_policy' => 'none',
            'policy_access_status' => 'active',
        ]);
        $token = $customer->createToken('active-blocks-free', ['customer'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/customer/free-trial')
            ->assertOk()
            ->assertJsonPath('campaign.id', $campaign->id)
            ->assertJsonPath('can_claim', false)
            ->assertJsonPath('claim_unavailable_reason', 'Available after your current package ends.');

        $this->withToken($token)->postJson('/api/v1/customer/free-trial/claim', [
            'campaign_id' => $campaign->id,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'You already have active internet at this location.');
    }

    public function test_unassigned_customer_does_not_receive_a_free_campaign(): void
    {
        $router = Router::factory()->create();
        $package = InternetPackage::factory()->create(['status' => 'active']);
        FreeTrialCampaign::create([
            'name' => 'Router Campaign',
            'package_id' => $package->id,
            'router_id' => $router->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['home_router_id' => null]);
        $token = $customer->createToken('free-campaign-unassigned', ['customer'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/customer/free-trial')
            ->assertOk()
            ->assertJsonPath('campaign', null);
    }
}
