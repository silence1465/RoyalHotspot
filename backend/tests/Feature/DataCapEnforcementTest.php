<?php

namespace Tests\Feature;

use App\Jobs\ApplyUsagePolicyJob;
use App\Models\Customer;
use App\Models\HotspotSession;
use App\Models\HotspotUser;
use App\Models\InternetPackage;
use App\Models\Purchase;
use App\Models\Router;
use App\Models\User;
use App\Services\FupService;
use App\Services\MikrotikService;
use App\Services\MikrotikServiceFactory;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DataCapEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_or_excess_usage_expires_and_disconnects_data_cap(): void
    {
        foreach ([10 * 1024 * 1024, (10 * 1024 * 1024) + 1] as $index => $used) {
            [$purchase, $hotspotUser, $session, $router] = $this->records('cap-'.$index, $used);
            $mikrotik = Mockery::mock(MikrotikService::class);
            $mikrotik->shouldReceive('disableAndDisconnectHotspotUser')
                ->once()->with($hotspotUser->username, $hotspotUser->mikrotik_user_id)
                ->andReturn(['success' => true]);
            $factory = $this->factory($mikrotik);

            (new ApplyUsagePolicyJob($purchase->id))->handle(
                app(FupService::class),
                new PurchaseService($factory),
                $factory,
            );

            $this->assertSame('expired', $purchase->fresh()->status);
            $this->assertSame('data_exhausted', $purchase->fresh()->policy_access_status);
            $this->assertTrue($hotspotUser->fresh()->disabled);
            $this->assertSame('data_limit_reached', $session->fresh()->disconnect_reason);
            $this->assertDatabaseHas('activity_logs', [
                'action' => 'purchase.data_limit_reached',
                'customer_id' => $purchase->customer_id,
            ]);
        }
    }

    public function test_package_edit_does_not_change_purchase_allowance_snapshot(): void
    {
        [$purchase] = $this->records('snapshot', 0);
        $this->assertSame(10 * 1024 * 1024, $purchase->data_allowance_bytes);

        $purchase->package->update(['data_limit' => '100M', 'data_allowance_bytes' => 100 * 1024 * 1024]);

        $this->assertSame(10 * 1024 * 1024, $purchase->fresh()->data_allowance_bytes);
    }

    public function test_new_purchase_gets_fresh_allowance_after_previous_exhaustion(): void
    {
        [$old, $hotspotUser] = $this->records('renew-cap', 10 * 1024 * 1024);
        $old->update(['status' => 'expired', 'policy_access_status' => 'data_exhausted']);
        $hotspotUser->update(['disabled' => true, 'last_bytes_in' => 999, 'last_bytes_out' => 999]);

        $new = $old->replicate();
        $new->fill([
            'reference' => 'RW-FRESH-CAP',
            'status' => 'verified',
            'starts_at' => null,
            'expires_at' => null,
            'cycle_bytes_used' => 999,
            'policy_access_status' => 'data_exhausted',
        ])->save();

        app(FupService::class)->snapshotPolicy($new, $new->package);

        $this->assertSame(0, $new->fresh()->cycle_bytes_used);
        $this->assertSame(10 * 1024 * 1024, $new->fresh()->data_allowance_bytes);
        $this->assertSame('active', $new->fresh()->policy_access_status);
    }

    public function test_repeated_poll_and_router_counter_reset_do_not_double_count_or_go_negative(): void
    {
        Queue::fake();
        [$purchase, , , $router] = $this->records('counter-cap', 0);
        $responses = [
            ['bytes-in' => 600, 'bytes-out' => 400],
            ['bytes-in' => 600, 'bytes-out' => 400],
            ['bytes-in' => 100, 'bytes-out' => 50],
        ];
        $mikrotik = Mockery::mock(MikrotikService::class);
        foreach ($responses as $counters) {
            $mikrotik->shouldReceive('getHotspotUsers')->once()->andReturn([
                'success' => true,
                'data' => [['name' => 'counter-cap'] + $counters],
            ]);
        }
        $this->app->instance(MikrotikServiceFactory::class, $this->factory($mikrotik));

        $this->artisan('bandwidth:snapshot')->expectsOutput('Polled 1 hotspot user counter(s) across live routers.')->assertSuccessful();
        $this->artisan('bandwidth:snapshot')->expectsOutput('Polled 1 hotspot user counter(s) across live routers.')->assertSuccessful();
        $this->artisan('bandwidth:snapshot')->expectsOutput('Polled 1 hotspot user counter(s) across live routers.')->assertSuccessful();

        $this->assertSame(1150, $purchase->fresh()->cycle_bytes_used);
        $this->assertDatabaseHas('bandwidth_logs', [
            'customer_id' => $purchase->customer_id,
            'router_id' => $router->id,
            'bytes_in' => 700,
            'bytes_out' => 450,
        ]);
    }

    public function test_admin_creates_mb_cap_with_router_profile_and_cannot_publish_unmapped_live_package(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $token = $admin->createToken('package-cap-test', ['admin'])->plainTextToken;
        $router = Router::factory()->create(['connection_mode' => 'manual']);
        config(['mikrotik_security.key_hash' => Hash::make('dummy-secure-key')]);
        $this->withToken($token)->postJson('/api/v1/admin/mikrotik-security/unlock', [
            'security_key' => 'dummy-secure-key',
        ])->assertOk();

        $payload = [
            'name' => 'TEST DATA',
            'price' => 5,
            'duration_value' => 1,
            'duration_unit' => 'days',
            'momo_bonus_value' => 0,
            'momo_bonus_unit' => 'days',
            'speed_limit' => '3M/3M',
            'usage_policy' => 'data_cap',
            'fup_period' => 'cycle',
            'data_allowance_value' => 10,
            'data_allowance_unit' => 'MB',
            'tier1_threshold_percent' => 60,
            'tier2_threshold_percent' => 85,
            'tier1_speed_percent' => 100,
            'tier2_speed_percent' => 70,
            'tier3_speed_percent' => 30,
            'status' => 'active',
            'sales_channel' => 'subscription',
            'profiles' => [],
        ];

        $this->withToken($token)->postJson('/api/v1/admin/packages', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profiles');

        $response = $this->withToken($token)->postJson('/api/v1/admin/packages', array_merge($payload, [
            'profiles' => [[
                'router_id' => $router->id,
                'profile_name' => 'test-data-10mb',
                'shared_users' => 1,
            ]],
        ]))->assertCreated();

        $packageId = $response->json('id');
        $this->assertDatabaseHas('internet_packages', [
            'id' => $packageId,
            'data_limit' => '10M',
            'usage_policy' => 'data_cap',
            'data_allowance_bytes' => 10 * 1024 * 1024,
        ]);
        $this->assertDatabaseHas('router_package_profiles', [
            'package_id' => $packageId,
            'router_id' => $router->id,
            'profile_name' => 'test-data-10mb',
            'shared_users' => 1,
        ]);
    }

    private function records(string $username, int $used): array
    {
        $customer = Customer::factory()->active()->create(['username' => $username]);
        $router = Router::factory()->create(['connection_mode' => 'live']);
        $package = InternetPackage::factory()->create([
            'data_limit' => '10M',
            'usage_policy' => 'data_cap',
            'data_allowance_bytes' => 10 * 1024 * 1024,
        ]);
        $purchase = Purchase::create([
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'router_id' => $router->id,
            'subtotal' => 5,
            'payment_fee' => 0,
            'amount' => 5,
            'reference' => 'RW-'.strtoupper(substr(md5($username), 0, 8)),
            'payment_method' => 'paystack',
            'fulfillment_type' => 'live',
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addDay(),
            'usage_policy' => 'data_cap',
            'data_allowance_bytes' => 10 * 1024 * 1024,
            'cycle_bytes_used' => $used,
            'policy_access_status' => 'active',
        ]);
        $customer->update(['current_purchase_id' => $purchase->id]);
        $hotspotUser = HotspotUser::create([
            'customer_id' => $customer->id,
            'router_id' => $router->id,
            'username' => $username,
            'password' => 'secret123',
            'mikrotik_user_id' => '*CAP',
            'profile' => 'data-cap',
            'disabled' => false,
        ]);
        $session = HotspotSession::create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'purchase_id' => $purchase->id,
            'router_id' => $router->id,
            'mikrotik_username' => $username,
            'status' => 'active',
            'started_at' => now(),
        ]);

        return [$purchase, $hotspotUser, $session, $router];
    }

    private function factory(MikrotikService $mikrotik): MikrotikServiceFactory
    {
        $factory = Mockery::mock(MikrotikServiceFactory::class);
        $factory->shouldReceive('make')->andReturn($mikrotik);

        return $factory;
    }
}
