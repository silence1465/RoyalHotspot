<?php

namespace App\Jobs;

use App\Models\BandwidthLog;
use App\Models\HotspotUser;
use App\Models\Purchase;
use App\Services\FupService;
use App\Services\MikrotikService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ApplyUsagePolicyJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $uniqueFor = 120;
    public array $backoff = [10, 30, 90];

    public function __construct(public int $purchaseId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->purchaseId;
    }

    public function handle(FupService $fup): void
    {
        $purchase = Purchase::with(['package', 'router'])->findOrFail($this->purchaseId);
        if ($purchase->status !== 'active' || ! $purchase->customer_id || ! $purchase->router_id) {
            return;
        }

        $hotspotUser = HotspotUser::where('customer_id', $purchase->customer_id)
            ->where('router_id', $purchase->router_id)
            ->first();
        if (! $hotspotUser?->mikrotik_user_id) {
            return;
        }

        $dailyBytes = (int) BandwidthLog::where('customer_id', $purchase->customer_id)
            ->where('router_id', $purchase->router_id)
            ->whereDate('date', now()->toDateString())
            ->selectRaw('COALESCE(bytes_in + bytes_out, 0) as total')
            ->value('total');
        $decision = $fup->evaluate($purchase, $dailyBytes);
        $mikrotik = new MikrotikService($purchase->router);

        if ($decision['exhausted']) {
            if ($purchase->policy_access_status === 'data_exhausted') {
                return;
            }
            $result = $mikrotik->disableAndDisconnectHotspotUser($hotspotUser->username, $hotspotUser->mikrotik_user_id);
            if (! $result['success']) {
                throw new \RuntimeException($result['error'] ?? 'MikroTik data-cap enforcement failed.');
            }
            $purchase->update(['policy_access_status' => 'data_exhausted', 'usage_policy_applied_at' => now()]);
            $hotspotUser->update(['disabled' => true]);
            return;
        }

        if ($purchase->usage_policy !== 'fup') {
            return;
        }

        $rate = $fup->scaledRateLimit($purchase->base_speed_limit, $decision['speed_percent']);
        if (! $rate || ($purchase->current_fup_tier === $decision['tier'] && $purchase->applied_speed_limit === $rate)) {
            return;
        }

        $result = $mikrotik->setUserRateAndReconnect(
            $hotspotUser->mikrotik_user_id,
            $hotspotUser->username,
            $rate
        );
        if (! $result['success']) {
            throw new \RuntimeException($result['error'] ?? 'MikroTik FUP update failed.');
        }

        $purchase->update([
            'current_fup_tier' => $decision['tier'],
            'applied_speed_limit' => $rate,
            'usage_policy_applied_at' => now(),
            'policy_access_status' => 'active',
        ]);
    }
}
