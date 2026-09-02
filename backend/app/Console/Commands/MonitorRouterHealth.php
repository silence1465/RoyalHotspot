<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Router;
use App\Services\MikrotikService;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class MonitorRouterHealth extends Command
{
    protected $signature = 'routers:monitor';

    protected $description = 'Check every live router\'s connectivity and notify on an online-to-offline transition';

    public function handle(TelegramService $telegram): int
    {
        $checked = 0;
        $wentOffline = 0;

        foreach (Router::where('connection_mode', 'live')->get() as $router) {
            $previousStatus = $router->status;

            $mikrotik = new MikrotikService($router);
            $result = $mikrotik->testConnection();
            $newStatus = $result['success'] ? 'online' : 'offline';

            $router->update(['status' => $newStatus]);
            $checked++;

            if ($previousStatus !== 'offline' && $newStatus === 'offline') {
                $message = "🔴 Router '{$router->name}' just went offline.";

                $telegram->send($message);

                ActivityLog::record('router.went_offline', $message);

                $wentOffline++;
            }
        }

        $this->info("Checked {$checked} live router(s), {$wentOffline} newly offline.");

        return self::SUCCESS;
    }
}
