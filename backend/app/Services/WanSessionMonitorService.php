<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\IspSessionSnapshot;
use App\Models\Router;
use App\Models\RouterIsp;

class WanSessionMonitorService
{
    public function __construct(protected MikrotikServiceFactory $mikrotikFactory) {}

    public function poll(Router $router): array
    {
        $isps = $router->isps()
            ->where('enabled', true)
            ->where('session_monitoring_enabled', true)
            ->whereNotNull('connection_mark')
            ->get();

        if ($isps->isEmpty()) {
            return ['success' => true, 'snapshots' => [], 'customers' => []];
        }

        $mikrotik = $this->mikrotikFactory->make($router);
        $connections = $mikrotik->getConnectionTrackingSnapshot();
        if (! $connections['success']) {
            return ['success' => false, 'error' => $connections['error'] ?? 'Connection snapshot failed.'];
        }

        $activeUsers = $mikrotik->getActiveUsers();
        $ipToUsername = $activeUsers['success']
            ? collect($activeUsers['data'])->filter(fn ($row) => isset($row['address'], $row['user']))
                ->mapWithKeys(fn ($row) => [(string) $row['address'] => (string) $row['user']])->all()
            : [];

        $aggregated = $this->aggregate($connections['data'] ?? [], $isps->pluck('connection_mark')->all(), $ipToUsername);
        $saved = [];

        foreach ($isps as $isp) {
            $counts = $aggregated['isps'][$isp->connection_mark] ?? ['tcp' => 0, 'udp' => 0, 'total' => 0, 'unattributed' => 0];
            $previous = $isp->latestSessionSnapshot()->first();
            $state = $this->state($isp, $counts['total']);
            $utilization = $isp->session_hard_limit
                ? round(($counts['total'] / $isp->session_hard_limit) * 100, 2)
                : null;

            $snapshot = IspSessionSnapshot::create([
                'router_id' => $router->id,
                'router_isp_id' => $isp->id,
                'tcp_sessions' => $counts['tcp'],
                'udp_sessions' => $counts['udp'],
                'total_sessions' => $counts['total'],
                'unattributed_sessions' => $counts['unattributed'],
                'utilization_percent' => $utilization,
                'state' => $state,
                'recorded_at' => now(),
            ]);

            if ($previous && $previous->state !== $state) {
                ActivityLog::record(
                    'isp.session_state_changed',
                    "ISP {$isp->name} on {$router->name} changed from {$previous->state} to {$state} at {$counts['total']} estimated WAN sessions."
                );
            }

            $saved[] = $snapshot;
        }

        return ['success' => true, 'snapshots' => $saved, 'customers' => $aggregated['customers']];
    }

    public function aggregate(array $connections, array $connectionMarks, array $ipToUsername = []): array
    {
        $marks = array_fill_keys(array_filter($connectionMarks), true);
        $isps = [];
        $customers = [];

        foreach ($connections as $connection) {
            $protocol = strtolower((string) ($connection['protocol'] ?? ''));
            $mark = (string) ($connection['connection-mark'] ?? '');
            if (! isset($marks[$mark]) || ! in_array($protocol, ['tcp', 'udp'], true)) {
                continue;
            }

            $isps[$mark] ??= ['tcp' => 0, 'udp' => 0, 'total' => 0, 'unattributed' => 0];
            $isps[$mark][$protocol]++;
            $isps[$mark]['total']++;

            $sourceIp = $this->sourceIp((string) ($connection['src-address'] ?? ''));
            $username = $sourceIp ? ($ipToUsername[$sourceIp] ?? null) : null;
            if (! $username) {
                $isps[$mark]['unattributed']++;

                continue;
            }

            $key = $mark.'|'.$username;
            $customers[$key] ??= [
                'connection_mark' => $mark,
                'username' => $username,
                'ip_addresses' => [],
                'tcp' => 0,
                'udp' => 0,
                'total' => 0,
            ];
            $customers[$key][$protocol]++;
            $customers[$key]['total']++;
            $customers[$key]['ip_addresses'][$sourceIp] = true;
        }

        $customers = array_values(array_map(function ($row) {
            $row['ip_addresses'] = array_keys($row['ip_addresses']);

            return $row;
        }, $customers));

        return ['isps' => $isps, 'customers' => $customers];
    }

    public function state(RouterIsp $isp, int $total): string
    {
        if ($isp->session_emergency_limit && $total >= $isp->session_emergency_limit) {
            return 'emergency';
        }
        if ($isp->session_hard_limit && $total >= $isp->session_hard_limit) {
            return 'critical';
        }
        if ($isp->session_soft_limit && $total >= $isp->session_soft_limit) {
            return 'warning';
        }

        return 'normal';
    }

    protected function sourceIp(string $address): ?string
    {
        if (preg_match('/^\[([^]]+)](?::\d+)?$/', $address, $matches)) {
            return $matches[1];
        }
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})(?::\d+)?$/', $address, $matches)) {
            return $matches[1];
        }

        return filter_var($address, FILTER_VALIDATE_IP) ? $address : null;
    }
}
