<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\RouterIsp;
use App\Services\MikrotikService;
use App\Services\MikrotikServiceFactory;
use App\Services\WanSessionMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WanSessionMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_snapshot_counts_tcp_udp_isps_customers_and_unattributed_connections(): void
    {
        $monitor = app(WanSessionMonitorService::class);
        $result = $monitor->aggregate([
            ['protocol' => 'tcp', 'connection-mark' => 'royal-mtn', 'src-address' => '10.5.50.2:50100'],
            ['protocol' => 'udp', 'connection-mark' => 'royal-mtn', 'src-address' => '10.5.50.2:50200'],
            ['protocol' => 'tcp', 'connection-mark' => 'royal-mtn', 'src-address' => '10.5.50.99:50300'],
            ['protocol' => 'udp', 'connection-mark' => 'royal-voda', 'src-address' => '10.5.50.3:50400'],
            ['protocol' => 'icmp', 'connection-mark' => 'royal-mtn', 'src-address' => '10.5.50.2'],
            ['protocol' => 'tcp', 'connection-mark' => '', 'src-address' => '10.5.50.2:50500'],
            ['malformed' => true],
        ], ['royal-mtn', 'royal-voda'], [
            '10.5.50.2' => 'opoku',
            '10.5.50.3' => 'harriet',
        ]);

        $this->assertSame(['tcp' => 2, 'udp' => 1, 'total' => 3, 'unattributed' => 1], $result['isps']['royal-mtn']);
        $this->assertSame(['tcp' => 0, 'udp' => 1, 'total' => 1, 'unattributed' => 0], $result['isps']['royal-voda']);
        $opoku = collect($result['customers'])->firstWhere('username', 'opoku');
        $this->assertSame(1, $opoku['tcp']);
        $this->assertSame(1, $opoku['udp']);
        $this->assertSame(2, $opoku['total']);
    }

    public function test_poll_stores_isolated_per_isp_snapshots_and_state_transitions(): void
    {
        $router = Router::factory()->create(['connection_mode' => 'live']);
        $mtn = $this->isp($router, 'MTN', 'royal-mtn');
        $voda = $this->isp($router, 'Voda', 'royal-voda');

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('getConnectionTrackingSnapshot')->once()->andReturn([
            'success' => true,
            'data' => array_merge(
                array_fill(0, 8, ['protocol' => 'tcp', 'connection-mark' => 'royal-mtn', 'src-address' => '10.5.50.2:1000']),
                array_fill(0, 3, ['protocol' => 'udp', 'connection-mark' => 'royal-voda', 'src-address' => '10.5.50.3:1001'])
            ),
        ]);
        $mikrotik->shouldReceive('getActiveUsers')->once()->andReturn([
            'success' => true,
            'data' => [
                ['address' => '10.5.50.2', 'user' => 'opoku'],
                ['address' => '10.5.50.3', 'user' => 'harriet'],
            ],
        ]);
        $factory = Mockery::mock(MikrotikServiceFactory::class);
        $factory->shouldReceive('make')->once()->withArgs(fn ($value) => $value->is($router))->andReturn($mikrotik);

        $result = (new WanSessionMonitorService($factory))->poll($router);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('isp_session_snapshots', [
            'router_isp_id' => $mtn->id, 'tcp_sessions' => 8, 'udp_sessions' => 0,
            'total_sessions' => 8, 'state' => 'warning',
        ]);
        $this->assertDatabaseHas('isp_session_snapshots', [
            'router_isp_id' => $voda->id, 'tcp_sessions' => 0, 'udp_sessions' => 3,
            'total_sessions' => 3, 'state' => 'normal',
        ]);
    }

    public function test_empty_and_large_connection_tables_are_processed_without_per_customer_queries(): void
    {
        $monitor = app(WanSessionMonitorService::class);
        $this->assertSame([], $monitor->aggregate([], ['royal-mtn'])['isps']);

        $rows = array_fill(0, 5000, [
            'protocol' => 'tcp',
            'connection-mark' => 'royal-mtn',
            'src-address' => '10.5.50.2:12345',
        ]);
        $result = $monitor->aggregate($rows, ['royal-mtn'], ['10.5.50.2' => 'load-test']);
        $this->assertSame(5000, $result['isps']['royal-mtn']['tcp']);
        $this->assertSame(5000, $result['customers'][0]['total']);
    }

    public function test_threshold_boundaries_produce_normal_warning_critical_and_emergency_states(): void
    {
        $router = Router::factory()->make();
        $isp = new RouterIsp([
            'session_soft_limit' => 800,
            'session_hard_limit' => 1000,
            'session_emergency_limit' => 1100,
        ]);
        $monitor = app(WanSessionMonitorService::class);

        $this->assertSame('normal', $monitor->state($isp, 799));
        $this->assertSame('warning', $monitor->state($isp, 800));
        $this->assertSame('warning', $monitor->state($isp, 999));
        $this->assertSame('critical', $monitor->state($isp, 1000));
        $this->assertSame('critical', $monitor->state($isp, 1099));
        $this->assertSame('emergency', $monitor->state($isp, 1100));
    }

    public function test_disabled_monitoring_does_not_contact_router_or_store_snapshot(): void
    {
        $router = Router::factory()->create(['connection_mode' => 'live']);
        $isp = $this->isp($router, 'MTN', 'royal-mtn');
        $isp->update(['session_monitoring_enabled' => false]);
        $factory = Mockery::mock(MikrotikServiceFactory::class);
        $factory->shouldNotReceive('make');

        $result = (new WanSessionMonitorService($factory))->poll($router);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['snapshots']);
        $this->assertDatabaseCount('isp_session_snapshots', 0);
    }

    public function test_router_failure_preserves_history_instead_of_recording_false_zero(): void
    {
        $router = Router::factory()->create(['connection_mode' => 'live']);
        $isp = $this->isp($router, 'MTN', 'royal-mtn');
        $isp->sessionSnapshots()->create([
            'router_id' => $router->id,
            'tcp_sessions' => 700,
            'udp_sessions' => 100,
            'total_sessions' => 800,
            'unattributed_sessions' => 0,
            'utilization_percent' => 80,
            'state' => 'warning',
            'recorded_at' => now()->subMinute(),
        ]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('getConnectionTrackingSnapshot')->once()->andReturn([
            'success' => false,
            'error' => 'Router unavailable',
        ]);
        $mikrotik->shouldNotReceive('getActiveUsers');
        $factory = Mockery::mock(MikrotikServiceFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($mikrotik);

        $result = (new WanSessionMonitorService($factory))->poll($router);

        $this->assertFalse($result['success']);
        $this->assertSame('Router unavailable', $result['error']);
        $this->assertDatabaseCount('isp_session_snapshots', 1);
        $this->assertSame(800, $isp->sessionSnapshots()->first()->total_sessions);
    }

    public function test_hotspot_lookup_failure_keeps_isp_total_and_marks_connections_unattributed(): void
    {
        $router = Router::factory()->create(['connection_mode' => 'live']);
        $isp = $this->isp($router, 'MTN', 'royal-mtn');
        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('getConnectionTrackingSnapshot')->once()->andReturn([
            'success' => true,
            'data' => [
                ['protocol' => 'tcp', 'connection-mark' => 'royal-mtn', 'src-address' => '10.5.50.2:1234'],
                ['protocol' => 'udp', 'connection-mark' => 'royal-mtn', 'src-address' => '10.5.50.3:5678'],
            ],
        ]);
        $mikrotik->shouldReceive('getActiveUsers')->once()->andReturn([
            'success' => false,
            'error' => 'Hotspot query unavailable',
        ]);
        $factory = Mockery::mock(MikrotikServiceFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($mikrotik);

        $result = (new WanSessionMonitorService($factory))->poll($router);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['customers']);
        $this->assertDatabaseHas('isp_session_snapshots', [
            'router_isp_id' => $isp->id,
            'tcp_sessions' => 1,
            'udp_sessions' => 1,
            'total_sessions' => 2,
            'unattributed_sessions' => 2,
        ]);
    }

    protected function isp(Router $router, string $name, string $mark): RouterIsp
    {
        return RouterIsp::create([
            'router_id' => $router->id,
            'name' => $name,
            'wan_interface' => strtolower($name).'-wan',
            'gateway' => '192.168.'.random_int(1, 200).'.1',
            'routing_table' => 'to-'.strtolower($name),
            'connection_mark' => $mark,
            'priority' => 1,
            'enabled' => true,
            'session_monitoring_enabled' => true,
            'session_soft_limit' => 5,
            'session_hard_limit' => 10,
            'session_emergency_limit' => 15,
        ]);
    }
}
