<?php

namespace App\Console\Commands;

use App\Models\BandwidthLog;
use App\Models\HotspotUser;
use App\Models\Router;
use App\Models\Purchase;
use App\Jobs\ApplyUsagePolicyJob;
use App\Services\MikrotikService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SnapshotBandwidthUsage extends Command
{
    protected $signature = 'bandwidth:snapshot';

    protected $description = 'Poll active sessions on every live router and accumulate bandwidth deltas into daily logs';

    public function handle(): int
    {
        $today = now()->toDateString();
        $polled = 0;

        foreach (Router::where('connection_mode', 'live')->get() as $router) {
            $mikrotik = new MikrotikService($router);
            $result = $mikrotik->getActiveUsers();

            if (! $result['success']) {
                continue;
            }

            foreach ($result['data'] as $session) {
                $username = $session['user'] ?? null;
                if (! $username) {
                    continue;
                }

                $hotspotUser = HotspotUser::where('router_id', $router->id)
                    ->where('username', $username)
                    ->first();

                if (! $hotspotUser) {
                    continue;
                }

                $currentIn = (int) ($session['bytes-in'] ?? 0);
                $currentOut = (int) ($session['bytes-out'] ?? 0);

                $deltaIn = $currentIn >= $hotspotUser->last_bytes_in
                    ? $currentIn - $hotspotUser->last_bytes_in
                    : $currentIn;
                $deltaOut = $currentOut >= $hotspotUser->last_bytes_out
                    ? $currentOut - $hotspotUser->last_bytes_out
                    : $currentOut;

                $purchaseId = DB::transaction(function () use ($hotspotUser, $router, $today, $deltaIn, $deltaOut, $currentIn, $currentOut) {
                    $log = BandwidthLog::firstOrNew([
                        'customer_id' => $hotspotUser->customer_id,
                        'router_id' => $router->id,
                        'date' => $today,
                    ]);
                    $log->bytes_in = ($log->bytes_in ?? 0) + $deltaIn;
                    $log->bytes_out = ($log->bytes_out ?? 0) + $deltaOut;
                    $log->save();

                    $hotspotUser->update([
                        'last_bytes_in' => $currentIn,
                        'last_bytes_out' => $currentOut,
                        'last_polled_at' => now(),
                    ]);

                    $purchase = Purchase::where('customer_id', $hotspotUser->customer_id)
                        ->where('router_id', $router->id)
                        ->where('fulfillment_type', 'live')
                        ->where('status', 'active')
                        ->where(function ($query) {
                            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        })
                        ->latest('id')
                        ->lockForUpdate()
                        ->first();

                    if ($purchase && in_array($purchase->usage_policy, ['fup', 'data_cap'], true)) {
                        $purchase->increment('cycle_bytes_used', $deltaIn + $deltaOut);
                        return $purchase->id;
                    }

                    return null;
                });

                if ($purchaseId) {
                    ApplyUsagePolicyJob::dispatch($purchaseId);
                }

                $polled++;
            }
        }

        $this->info("Polled {$polled} active session(s) across live routers.");

        return self::SUCCESS;
    }
}
