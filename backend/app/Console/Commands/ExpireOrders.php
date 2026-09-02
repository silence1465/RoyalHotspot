<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Order;
use Illuminate\Console\Command;

class ExpireOrders extends Command
{
    protected $signature = 'orders:expire';

    protected $description = 'Expire pending/processing Royal WiFi orders past their expires_at';

    public function handle(): int
    {
        $count = 0;

        Order::whereIn('status', ['pending', 'processing'])
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    $order->update(['status' => 'expired']);

                    ActivityLog::record(
                        'order.expired',
                        "Order {$order->reference} expired without payment.",
                        ['customer_id' => $order->customer_id]
                    );

                    $count++;
                }
            });

        $this->info("Expired {$count} order(s).");

        return self::SUCCESS;
    }
}
