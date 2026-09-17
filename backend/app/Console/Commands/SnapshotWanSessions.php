<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\WanSessionMonitorService;
use Illuminate\Console\Command;

class SnapshotWanSessions extends Command
{
    protected $signature = 'wan-sessions:snapshot {--router=}';

    protected $description = 'Record estimated per-ISP TCP/UDP connection-tracking counts';

    public function handle(WanSessionMonitorService $monitor): int
    {
        $query = Router::where('connection_mode', 'live')
            ->whereHas('isps', fn ($isps) => $isps->where('enabled', true)->where('session_monitoring_enabled', true));
        if ($this->option('router')) {
            $query->whereKey($this->option('router'));
        }

        $failed = 0;
        $recorded = 0;
        foreach ($query->get() as $router) {
            $result = $monitor->poll($router);
            if (! $result['success']) {
                $failed++;
                $this->warn("{$router->name}: {$result['error']}");

                continue;
            }
            $recorded += count($result['snapshots']);
        }

        $this->info("Recorded {$recorded} ISP session snapshot(s); {$failed} router poll(s) failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
