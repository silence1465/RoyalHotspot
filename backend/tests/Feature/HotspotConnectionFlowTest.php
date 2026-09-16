<?php

namespace Tests\Feature;

use App\Jobs\ActivateHotspotUserJob;
use App\Models\Customer;
use App\Models\HotspotSession;
use App\Models\HotspotUser;
use App\Models\InternetPackage;
use App\Models\Purchase;
use App\Models\Router;
use App\Models\RouterPackageProfile;
use App\Services\HotspotSessionService;
use App\Services\MikrotikService;
use App\Services\MikrotikServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class HotspotConnectionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_embedded_routeros_command_error_is_not_treated_as_success(): void
    {
        $router = Router::factory()->create(['connection_mode' => 'live']);
        $service = new class($router) extends MikrotikService
        {
            public function detect(mixed $result): ?string
            {
                return $this->routerOsCommandError($result);
            }
        };

        $this->assertSame(
            'input does not match any value of profile',
            $service->detect(['after' => ['message' => 'input does not match any value of profile']])
        );
        $this->assertNull($service->detect([['.id' => '*1', 'name' => 'valid-user']]));
    }

    public function test_customer_sees_only_their_router_session_and_router_confirms_it(): void
    {
        [$customer, $router, $purchase] = $this->records('session-owner');
        $other = Customer::factory()->active()->create();
        $session = $this->activeSession($customer, $router, $purchase);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('getActiveUsers')->once()->andReturn([
            'success' => true,
            'data' => [['.id' => '*ACTIVE1', 'user' => 'session-owner', 'mac-address' => 'AA:BB:CC:DD:EE:FF', 'address' => '10.0.0.8']],
        ]);
        $this->bindFactory($mikrotik);

        $token = $customer->createToken('connection-test', ['customer'])->plainTextToken;
        $this->withToken($token)
            ->getJson("/api/v1/customer/hotspot/sessions/current?router_id={$router->id}")
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('session.session_id', $session->public_id)
            ->assertJsonPath('session.mikrotik_session_id', '*ACTIVE1');

        $this->app['auth']->forgetGuards();
        $otherToken = $other->createToken('connection-test-other', ['customer'])->plainTextToken;
        $this->withToken($otherToken)
            ->getJson("/api/v1/customer/hotspot/sessions/current?router_id={$router->id}")
            ->assertOk()
            ->assertJsonPath('connected', false)
            ->assertJsonPath('session', null);
    }

    public function test_missing_router_session_marks_local_session_disconnected(): void
    {
        [$customer, $router, $purchase] = $this->records('missing-user');
        $session = $this->activeSession($customer, $router, $purchase, 'missing-user');
        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('getActiveUsers')->once()->andReturn(['success' => true, 'data' => []]);

        $result = (new HotspotSessionService($this->factory($mikrotik)))->current($customer, $router->id);

        $this->assertFalse($result['connected']);
        $this->assertSame('disconnected', $session->fresh()->status);
        $this->assertNotNull($session->fresh()->ended_at);
    }

    public function test_temporary_router_failure_does_not_disconnect_known_session(): void
    {
        [$customer, $router, $purchase] = $this->records('unknown-user');
        $session = $this->activeSession($customer, $router, $purchase, 'unknown-user');
        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('getActiveUsers')->once()->andReturn(['success' => false, 'error' => 'timeout']);

        $result = (new HotspotSessionService($this->factory($mikrotik)))->current($customer, $router->id);

        $this->assertNull($result['connected']);
        $this->assertSame('unknown', $result['status']);
        $this->assertSame('active', $session->fresh()->status);
        $this->assertNull($session->fresh()->ended_at);
    }

    public function test_new_customer_credentials_are_verified_provisioned_and_confirmed(): void
    {
        [$customer, $router, $purchase] = $this->records('brand-new-user');
        $sentPassword = null;
        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('ensureHotspotUserProfile')->once()->andReturn(['success' => true, 'data' => []]);
        $mikrotik->shouldReceive('createHotspotUser')->once()
            ->with('brand-new-user', Mockery::on(function ($password) use (&$sentPassword) {
                $sentPassword = $password;

                return is_string($password) && strlen($password) === 10;
            }), 'weekly')
            ->andReturn(['success' => true, 'data' => []]);
        $mikrotik->shouldReceive('verifyHotspotUser')->once()->with('brand-new-user', 'weekly')->andReturn([
            'success' => true,
            'data' => ['mikrotik_user_id' => '*NEW1', 'username' => 'brand-new-user', 'profile' => 'weekly', 'disabled' => false],
        ]);
        $mikrotik->shouldReceive('getActiveUsers')->twice()->andReturn(
            ['success' => true, 'data' => []],
            ['success' => true, 'data' => [['.id' => '*SESSION1', 'user' => 'brand-new-user', 'mac-address' => 'AA:BB:CC:DD:EE:FF', 'address' => '10.0.0.8']]],
        );
        $factory = $this->factory($mikrotik);

        (new ActivateHotspotUserJob($purchase->id))->handle($factory);

        $hotspotUser = HotspotUser::where('customer_id', $customer->id)->where('router_id', $router->id)->firstOrFail();
        $this->assertSame('*NEW1', $hotspotUser->mikrotik_user_id);
        $this->assertSame($sentPassword, $hotspotUser->makeVisible('password')->password);

        $service = new HotspotSessionService($factory);
        $prepared = $service->prepare($customer, $router->id, 'http://login.hotspot.local/login', 'AA:BB:CC:DD:EE:FF', '10.0.0.8');
        $this->assertSame('brand-new-user', $prepared['username']);
        $this->assertSame($sentPassword, $prepared['password']);

        $session = HotspotSession::where('public_id', $prepared['session_id'])->with('router')->firstOrFail();
        $confirmed = $service->confirm($customer, $session);
        $this->assertSame('active', $confirmed->status);
        $this->assertSame('*SESSION1', $confirmed->mikrotik_session_id);
    }

    public function test_rejected_router_creation_does_not_create_a_usable_local_account(): void
    {
        [, , $purchase] = $this->records('rejected-user');
        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('ensureHotspotUserProfile')->once()->andReturn(['success' => true, 'data' => []]);
        $mikrotik->shouldReceive('createHotspotUser')->once()->andReturn([
            'success' => false,
            'error' => 'input does not match any value of profile',
        ]);
        $job = new ActivateHotspotUserJob($purchase->id);

        try {
            $job->handle($this->factory($mikrotik));
            $this->fail('Provisioning failure should throw for a queue retry.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('input does not match any value of profile', $exception->getMessage());
            $job->failed($exception);
        }

        $this->assertDatabaseCount('hotspot_users', 0);
        $this->assertSame('pending_activation', $purchase->fresh()->status);
    }

    private function records(string $username): array
    {
        $customer = Customer::factory()->active()->create(['username' => $username]);
        $router = Router::factory()->create([
            'connection_mode' => 'live',
            'hotspot_login_host' => 'login.hotspot.local',
        ]);
        $package = InternetPackage::factory()->create(['speed_limit' => '5M/5M']);
        RouterPackageProfile::create([
            'router_id' => $router->id,
            'package_id' => $package->id,
            'profile_name' => 'weekly',
        ]);
        $purchase = Purchase::create([
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'router_id' => $router->id,
            'subtotal' => 10,
            'payment_fee' => 0,
            'amount' => 10,
            'reference' => 'RW-'.strtoupper(substr(md5($username), 0, 8)),
            'payment_method' => 'momo',
            'fulfillment_type' => 'live',
            'status' => 'active',
            'verified_at' => now(),
            'starts_at' => now(),
            'expires_at' => now()->addWeek(),
            'usage_policy' => 'none',
            'policy_access_status' => 'active',
        ]);

        return [$customer, $router, $purchase];
    }

    private function activeSession(Customer $customer, Router $router, Purchase $purchase, ?string $username = null): HotspotSession
    {
        return HotspotSession::create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'purchase_id' => $purchase->id,
            'router_id' => $router->id,
            'mikrotik_username' => $username ?? $customer->username,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'ip_address' => '10.0.0.8',
            'status' => 'active',
            'started_at' => now()->subMinute(),
            'last_seen_at' => now()->subSecond(),
        ]);
    }

    private function factory(MikrotikService $mikrotik): MikrotikServiceFactory
    {
        $factory = Mockery::mock(MikrotikServiceFactory::class);
        $factory->shouldReceive('make')->andReturn($mikrotik);

        return $factory;
    }

    private function bindFactory(MikrotikService $mikrotik): void
    {
        $this->app->instance(MikrotikServiceFactory::class, $this->factory($mikrotik));
    }
}
