<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InternetPackage;
use App\Models\Purchase;
use App\Models\Router;
use App\Models\RouterIsp;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\CapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapacityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_data_capacity_is_reserved_atomically_and_queued_purchases_reserve_nothing(): void
    {
        SystemSetting::set('operating_mode', 'data_cap');
        [$router, $isp] = $this->topology(10);
        $package = InternetPackage::factory()->create([
            'usage_policy' => 'data_cap',
            'data_allowance_bytes' => 2 * 1024 * 1024 * 1024,
        ]);
        $capacity = app(CapacityService::class);

        $first = $this->purchase($router, $package, Customer::factory()->active()->create());
        $this->assertTrue($capacity->reserve($first)['reserved']);
        $this->assertSame(8 * 1024 * 1024 * 1024, $capacity->summary($isp)['remaining_bytes']);

        foreach (range(1, 4) as $unused) {
            $purchase = $this->purchase($router, $package, Customer::factory()->active()->create());
            $this->assertTrue($capacity->reserve($purchase)['reserved']);
        }

        $queued = $this->purchase($router, $package, Customer::factory()->active()->create());
        $result = $capacity->reserve($queued);
        $this->assertFalse($result['reserved']);
        $this->assertSame('data_capacity', $result['reason']);
        $this->assertSame(0, $queued->fresh()->capacity_reserved_bytes);
        $this->assertSame(0, $capacity->summary($isp)['remaining_bytes']);
    }

    public function test_user_cap_keeps_a_customer_on_the_same_isp_and_blocks_new_slots(): void
    {
        SystemSetting::set('operating_mode', 'user_cap');
        [$router, $primary] = $this->topology(100, 1, 'Primary', 10);
        $backup = RouterIsp::create([
            'router_id' => $router->id, 'name' => 'Backup', 'wan_interface' => 'ether2',
            'gateway' => '192.168.20.1', 'routing_table' => 'to-backup',
            'monthly_capacity_bytes' => 100 * 1024 * 1024 * 1024,
            'subscriber_limit' => 1, 'priority' => 20, 'enabled' => true,
        ]);
        $package = InternetPackage::factory()->create([
            'usage_policy' => 'data_cap', 'data_allowance_bytes' => 2 * 1024 * 1024 * 1024,
        ]);
        $capacity = app(CapacityService::class);

        $firstCustomer = Customer::factory()->active()->create();
        $first = $this->purchase($router, $package, $firstCustomer);
        $this->assertTrue($capacity->reserve($first)['reserved']);
        $this->assertSame($primary->id, $first->fresh()->router_isp_id);

        $second = $this->purchase($router, $package, Customer::factory()->active()->create());
        $this->assertTrue($capacity->reserve($second)['reserved']);
        $this->assertSame($backup->id, $second->fresh()->router_isp_id);

        $third = $this->purchase($router, $package, Customer::factory()->active()->create());
        $this->assertSame('subscriber_capacity', $capacity->reserve($third)['reason']);
    }

    public function test_used_balance_rolls_once_and_is_charged_to_the_new_month(): void
    {
        SystemSetting::set('operating_mode', 'data_cap');
        [$router, $isp] = $this->topology(10);
        $package = InternetPackage::factory()->create([
            'usage_policy' => 'data_cap', 'data_allowance_bytes' => 2 * 1024 * 1024 * 1024,
        ]);
        $purchase = $this->purchase($router, $package, Customer::factory()->active()->create());
        $purchase->update([
            'status' => 'active', 'router_isp_id' => $isp->id,
            'capacity_month' => now()->subMonthNoOverflow()->startOfMonth(),
            'capacity_reserved_bytes' => 2 * 1024 * 1024 * 1024,
            'data_allowance_bytes' => 2 * 1024 * 1024 * 1024,
            'cycle_bytes_used' => 512 * 1024 * 1024,
            'starts_at' => now()->subDays(3), 'expires_at' => now()->addDays(4),
        ]);

        app(CapacityService::class)->carryForward($purchase);
        $purchase->refresh();
        $this->assertSame(1536 * 1024 * 1024, $purchase->rollover_bytes);
        $this->assertTrue($purchase->capacity_month->isSameMonth(now()));
        $this->assertSame(1536 * 1024 * 1024, app(CapacityService::class)->summary($isp)['reserved_bytes']);
    }

    public function test_configured_capacity_modes_can_be_enabled_by_a_super_admin(): void
    {
        $this->topology(100, 200);
        $admin = User::factory()->superAdmin()->create();
        $token = $admin->createToken('capacity-mode', ['admin'])->plainTextToken;

        $this->withToken($token)->putJson('/api/v1/admin/operating-mode', ['mode' => 'data_cap'])
            ->assertOk()->assertJsonPath('mode', 'data_cap');
        $this->withToken($token)->putJson('/api/v1/admin/operating-mode', ['mode' => 'user_cap'])
            ->assertOk()->assertJsonPath('mode', 'user_cap');
        $this->withToken($token)->getJson('/api/v1/admin/operating-mode')
            ->assertOk()->assertJsonPath('mode', 'user_cap')
            ->assertJsonPath('available_modes.2', 'user_cap');
    }

    public function test_five_isp_l009_allocation_flow_with_capacity_expansion(): void
    {
        SystemSetting::set('operating_mode', 'user_cap');
        $router = Router::factory()->create([
            'name' => 'Dummy L009 Hotspot',
            'routeros_version' => '7.18.2',
            'isp_failover_enabled' => true,
            'isp_failback_enabled' => true,
        ]);

        $isps = collect(range(1, 5))->map(fn (int $number) => RouterIsp::create([
            'router_id' => $router->id,
            'name' => "Dummy ISP {$number}",
            'wan_interface' => "ether{$number}",
            'gateway' => "192.168.{$number}.1",
            'routing_table' => $number === 1 ? 'main' : "to-isp-{$number}",
            'monthly_capacity_bytes' => 4 * 1024 * 1024 * 1024,
            'subscriber_limit' => 2,
            'priority' => $number * 10,
            'enabled' => true,
        ]));
        $package = InternetPackage::factory()->create([
            'name' => 'Dummy 2 GB',
            'usage_policy' => 'data_cap',
            'data_allowance_bytes' => 2 * 1024 * 1024 * 1024,
        ]);
        $capacity = app(CapacityService::class);
        $allocated = collect();

        foreach (range(1, 10) as $number) {
            $purchase = $this->purchase($router, $package, Customer::factory()->active()->create([
                'username' => "dummy-l009-user-{$number}",
            ]));
            $this->assertTrue($capacity->reserve($purchase)['reserved']);
            $allocated->push($purchase->fresh());
        }

        foreach ($isps as $isp) {
            $summary = $capacity->summary($isp);
            $this->assertSame(2, $summary['subscribers_allocated']);
            $this->assertSame(4 * 1024 * 1024 * 1024, $summary['reserved_bytes']);
            $this->assertSame(0, $summary['remaining_bytes']);
            $this->assertSame(0, $summary['subscriber_slots_remaining']);
        }

        $waiting = $this->purchase($router, $package, Customer::factory()->active()->create([
            'username' => 'dummy-l009-waiting',
        ]));
        $this->assertSame('subscriber_capacity', $capacity->reserve($waiting)['reason']);
        $this->assertSame(0, $waiting->fresh()->capacity_reserved_bytes);

        $fifth = $isps->last();
        $fifth->update([
            'monthly_capacity_bytes' => 6 * 1024 * 1024 * 1024,
            'subscriber_limit' => 3,
        ]);
        $this->assertTrue($capacity->reserve($waiting->fresh())['reserved']);
        $this->assertSame($fifth->id, $waiting->fresh()->router_isp_id);
        $this->assertSame(0, $capacity->summary($fifth->fresh())['remaining_bytes']);

        $firstCustomer = $allocated->first()->customer_id;
        $stickyPackage = InternetPackage::factory()->create([
            'name' => 'Dummy 1 GB renewal',
            'usage_policy' => 'data_cap',
            'data_allowance_bytes' => 1024 * 1024 * 1024,
        ]);
        $primary = $isps->first();
        $primary->update([
            'monthly_capacity_bytes' => 5 * 1024 * 1024 * 1024,
            'subscriber_limit' => 3,
        ]);
        $renewal = $this->purchase($router, $stickyPackage, Customer::findOrFail($firstCustomer));
        $this->assertTrue($capacity->reserve($renewal)['reserved']);
        $this->assertSame($primary->id, $renewal->fresh()->router_isp_id);
    }

    private function topology(int $capacityGb, ?int $subscriberLimit = null, string $name = 'Primary', int $priority = 10): array
    {
        $router = Router::factory()->create();
        $isp = RouterIsp::create([
            'router_id' => $router->id, 'name' => $name, 'wan_interface' => 'ether1',
            'gateway' => '192.168.10.1', 'routing_table' => 'to-primary',
            'monthly_capacity_bytes' => $capacityGb * 1024 * 1024 * 1024,
            'subscriber_limit' => $subscriberLimit, 'priority' => $priority, 'enabled' => true,
        ]);

        return [$router, $isp];
    }

    private function purchase(Router $router, InternetPackage $package, Customer $customer): Purchase
    {
        return Purchase::create([
            'customer_id' => $customer->id, 'package_id' => $package->id, 'router_id' => $router->id,
            'amount' => 10, 'reference' => Purchase::generateReference(), 'payment_method' => 'momo',
            'fulfillment_type' => 'live', 'status' => 'verified', 'verified_at' => now(),
        ]);
    }
}
