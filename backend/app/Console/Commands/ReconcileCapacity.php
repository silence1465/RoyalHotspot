<?php

namespace App\Console\Commands;

use App\Models\Purchase;
use App\Services\CapacityService;
use App\Services\PurchaseService;
use Illuminate\Console\Command;

class ReconcileCapacity extends Command
{
    protected $signature = 'capacity:reconcile';

    protected $description = 'Carry eligible balances into the current month and activate paid capacity queues oldest-first';

    public function handle(CapacityService $capacity, PurchaseService $purchases): int
    {
        if ($capacity->mode() === 'normal') {
            return self::SUCCESS;
        }

        Purchase::whereIn('status', ['active', 'voucher_assigned', 'completed'])
            ->whereNotNull('router_isp_id')
            ->whereNotNull('capacity_month')
            ->whereDate('capacity_month', '<', now()->startOfMonth()->toDateString())
            ->orderBy('id')->eachById(fn (Purchase $purchase) => $capacity->carryForward($purchase));

        Purchase::where('status', 'queued')
            ->whereIn('queue_reason', ['data_capacity', 'subscriber_capacity'])
            ->orderBy('verified_at')->orderBy('id')
            ->eachById(function (Purchase $purchase) use ($purchases) {
                $purchases->fulfill($purchase->fresh());
            });

        return self::SUCCESS;
    }
}
