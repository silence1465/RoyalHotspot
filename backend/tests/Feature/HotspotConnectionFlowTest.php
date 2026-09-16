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
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_waiting_live_purchase_starts_once_and_valid_reconnect_keeps_its_timer(): void
    {
        [$customer, $router, $purchase] = $this->records('timer-user');
        $purchase->package->update(['duration_value' => 1, 'duration_unit' => 'hours']);
        $purchase->update(['starts_at' => null, 'expires_at' => null]);
        $this->hotspotUser($customer, $router, 'timer-user');

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('getActiveUsers')->times(3)->andReturn(
            ['success' => true, 'data' => []],
            ['success' => true, 'data' => [['.id' => '*TIMER', 'user' => 'timer-user', 'mac-address' => 'AA:BB:CC:DD:EE:FF', 'address' => '10.0.0.8']]],
            ['success' => true, 'data' => [['.id' => '*TIMER', 'user' => 'timer-user', 'mac-address' => 'AA:BB:CC:DD:EE:FF', 'address' => '10.0.0.8']]],
        );
        $factory = $this->factory($mikrotik);
        $this->app->instance(MikrotikServiceFactory::class, $factory);
        $service = new HotspotSessionService($factory);

        $prepared = $service->prepare($customer, $router->id, 'http://login.hotspot.local/login', 'AA:BB:CC:DD:EE:FF', '10.0.0.8');
        $startedAt = $purchase->fresh()->starts_at;
        $expiresAt = $purchase->fresh()->expires_at;
        $this->assertNotNull($startedAt);
        $this->assertEquals(60, $startedAt->diffInMinutes($expiresAt));

        $session = HotspotSession::where('public_id', $prepared['session_id'])->with('router')->firstOrFail();
        $service->confirm($customer, $session);
        $reconnect = $service->prepare($customer, $router->id, 'http://login.hotspot.local/login', 'AA:BB:CC:DD:EE:FF', '10.0.0.8');

        $this->assertTrue($reconnect['connected']);
        $this->assertTrue($purchase->fresh()->starts_at->equalTo($startedAt));
        $this->assertTrue($purchase->fresh()->expires_at->equalTo($expiresAt));
        $this->assertDatabaseCount('hotspot_sessions', 1);
    }

    public function test_waiting_purchase_is_not_authorized_by_an_existing_local_session(): void
    {
        [$customer, $router, $purchase] = $this->records('waiting-user');
        $purchase->update(['starts_at' => null, 'expires_at' => null]);
        $session = $this->activeSession($customer, $router, $purchase, 'waiting-user');
        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('disconnectAndClearHotspotCookies')
            ->once()->with('waiting-user', 'AA:BB:CC:DD:EE:FF')->andReturn(['success' => true]);

        $result = (new HotspotSessionService($this->factory($mikrotik)))->current($customer, $router->id);

        $this->assertFalse($result['connected']);
        $this->assertSame('replaced', $session->fresh()->status);
    }

    public function test_expired_session_cannot_authorize_new_purchase_and_new_timer_starts(): void
    {
        [$customer, $router, $oldPurchase] = $this->records('replacement-user');
        $oldPurchase->update(['status' => 'expired', 'starts_at' => now()->subHours(2), 'expires_at' => now()->subHour()]);
        $oldSession = $this->activeSession($customer, $router, $oldPurchase, 'replacement-user');
        $newPurchase = $oldPurchase->replicate(['reference', 'starts_at', 'expires_at', 'status']);
        $newPurchase->fill(['reference' => 'RW-REPLACEMENT', 'status' => 'active', 'starts_at' => null, 'expires_at' => null, 'verified_at' => now()])->save();
        $this->hotspotUser($customer, $router, 'replacement-user');

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('disconnectAndClearHotspotCookies')->once()->andReturn(['success' => true]);
        $mikrotik->shouldReceive('getActiveUsers')->once()->andReturn(['success' => true, 'data' => []]);
        $factory = $this->factory($mikrotik);
        $this->app->instance(MikrotikServiceFactory::class, $factory);

        $prepared = (new HotspotSessionService($factory))->prepare(
            $customer, $router->id, 'http://login.hotspot.local/login', 'AA:BB:CC:DD:EE:FF', '10.0.0.8'
        );

        $this->assertFalse($prepared['connected']);
        $this->assertSame('expired', $oldSession->fresh()->status);
        $this->assertNotNull($newPurchase->fresh()->starts_at);
        $this->assertDatabaseHas('hotspot_sessions', ['public_id' => $prepared['session_id'], 'purchase_id' => $newPurchase->id, 'status' => 'connecting']);
    }

    public function test_expiry_revokes_router_access_and_expires_matching_session(): void
    {
        [$customer, $router, $purchase] = $this->records('expiry-user');
        $purchase->update(['expires_at' => now()->subMinute()]);
        $session = $this->activeSession($customer, $router, $purchase, 'expiry-user');
        $hotspotUser = $this->hotspotUser($customer, $router, 'expiry-user');
        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('disableAndDisconnectHotspotUser')
            ->once()->with('expiry-user', $hotspotUser->mikrotik_user_id)
            ->andReturn(['success' => true, 'data' => ['disconnected_sessions' => 1, 'removed_cookies' => 1]]);

        (new PurchaseService($this->factory($mikrotik)))->expirePurchase($purchase);

        $this->assertSame('expired', $purchase->fresh()->status);
        $this->assertSame('expired', $session->fresh()->status);
        $this->assertTrue($hotspotUser->fresh()->disabled);
    }

    public function test_admin_grant_waits_for_first_connection_before_starting_timer(): void
    {
        Queue::fake();
        [$customer, $router, $purchase] = $this->records('grant-user');
        $purchase->update(['status' => 'verified', 'payment_method' => 'admin_grant', 'starts_at' => null, 'expires_at' => null]);
        $mikrotik = Mockery::mock(MikrotikService::class);
        $factory = $this->factory($mikrotik);
        $service = new PurchaseService($factory);
        $service->fulfill($purchase);
        $this->assertNull($purchase->fresh()->starts_at);
        $this->assertNull($purchase->fresh()->expires_at);

        $this->hotspotUser($customer, $router, 'grant-user');
        $mikrotik->shouldReceive('getActiveUsers')->once()->andReturn(['success' => true, 'data' => []]);
        $this->app->instance(MikrotikServiceFactory::class, $factory);
        (new HotspotSessionService($factory))->prepare(
            $customer, $router->id, 'http://login.hotspot.local/login', 'AA:BB:CC:DD:EE:FF', '10.0.0.8'
        );

        $this->assertNotNull($purchase->fresh()->starts_at);
        $this->assertNotNull($purchase->fresh()->expires_at);
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

    private function hotspotUser(Customer $customer, Router $router, string $username): HotspotUser
    {
        return HotspotUser::create([
            'customer_id' => $customer->id,
            'router_id' => $router->id,
            'username' => $username,
            'password' => 'hotspot-password',
            'mikrotik_user_id' => '*USER',
            'profile' => 'weekly',
            'disabled' => false,
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
