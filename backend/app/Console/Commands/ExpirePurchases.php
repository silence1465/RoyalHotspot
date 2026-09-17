<?php

namespace App\Console\Commands;

use App\Models\Purchase;
use App\Services\PurchaseService;
use Illuminate\Console\Command;
use Throwable;

class ExpirePurchases extends Command
{
    protected $signature = 'purchases:expire';

    protected $description = 'Expire purchases past their expires_at — both stale pending payments and active subscriptions that ran out of time';

    public function handle(PurchaseService $purchaseService): int
    {
        $expiredPending = 0;
        $expiredActive = 0;
        $failures = 0;

        // 1. Stale pending/processing purchases that never got paid
        Purchase::pendingExpiry()
            ->chunkById(100, function ($purchases) use (&$expiredPending) {
                foreach ($purchases as $purchase) {
                    $purchase->update(['status' => 'expired']);
                    $expiredPending++;
                }
            });

        // 2. Active purchases (live or voucher-fulfilled) whose time ran out
        Purchase::expiring()
            ->chunkById(100, function ($purchases) use ($purchaseService, &$expiredActive, &$failures) {
                foreach ($purchases as $purchase) {
                    try {
                        $purchaseService->expirePurchase($purchase);
                        $expiredActive++;
                    } catch (Throwable $exception) {
                        $failures++;
                        report($exception);
                        $this->warn("Purchase {$purchase->reference} could not be enforced and will be retried: {$exception->getMessage()}");
                    }
                }
            });

        $this->info("Expired {$expiredPending} unpaid purchase(s), {$expiredActive} active subscription(s).");

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
