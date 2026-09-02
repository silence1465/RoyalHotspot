<?php

namespace App\Console\Commands;

use App\Models\Voucher;
use App\Services\VoucherUsageSyncService;
use Illuminate\Console\Command;

/**
 * Deliberately NOT added to bootstrap/app.php's scheduler — this makes
 * one live RouterOS call per router (getHotspotUsers, listing every
 * hotspot user) and checks it against every 'assigned' voucher on that
 * router, which could be slow against a large inventory or an
 * unreachable router. Run manually first (`php artisan vouchers:sync-mikrotik-status`)
 * to confirm it behaves as expected against a real router before ever
 * putting it on a schedule.
 */
class SyncVoucherMikrotikStatus extends Command
{
    protected $signature = 'vouchers:sync-mikrotik-status {--router= : Only sync vouchers for this router ID}';

    protected $description = 'Check MikroTik for actual usage of assigned Royal WiFi vouchers and mark them used if confirmed';

    public function handle(VoucherUsageSyncService $syncService): int
    {
        if (! config('voucherimport.mikrotik_sync_enabled')) {
            $this->warn('VOUCHER_MIKROTIK_SYNC_ENABLED is false — set it in .env to run this command. See config/voucherimport.php.');
            return self::FAILURE;
        }

        $query = Voucher::where('status', 'assigned')->whereNotNull('router_id');

        if ($routerId = $this->option('router')) {
            $query->where('router_id', $routerId);
        }

        $checked = 0;
        $confirmedUsed = 0;
        $errors = 0;

        $query->chunkById(50, function ($vouchers) use ($syncService, &$checked, &$confirmedUsed, &$errors) {
            foreach ($vouchers as $voucher) {
                $result = $syncService->checkVoucher($voucher);
                $checked++;

                if (! $result['success']) {
                    $errors++;
                    continue;
                }

                if ($result['has_been_used']) {
                    $confirmedUsed++;
                }
            }
        });

        $this->info("Checked {$checked} voucher(s): {$confirmedUsed} confirmed used, {$errors} could not be checked.");

        return self::SUCCESS;
    }
}
