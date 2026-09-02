<?php

namespace App\Jobs;

use App\Mail\VoucherPurchaseMail;
use App\Models\ActivityLog;
use App\Models\Purchase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendVoucherPurchaseEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    public function __construct(public int $purchaseId)
    {
    }

    public function handle(): void
    {
        $purchase = Purchase::with(['customer', 'voucher', 'package'])->findOrFail($this->purchaseId);

        if (! $purchase->customer || ! $purchase->customer->email) {
            ActivityLog::record(
                'email.skipped_no_address',
                "No email on file for purchase {$purchase->reference} — voucher still assigned and visible in-app.",
                $purchase->customer_id ? ['customer_id' => $purchase->customer_id] : []
            );
            return;
        }

        Mail::to($purchase->customer->email)->send(new VoucherPurchaseMail($purchase));

        ActivityLog::record(
            'email.sent',
            "Voucher purchase email sent to {$purchase->customer->email} for purchase {$purchase->reference}.",
            ['customer_id' => $purchase->customer_id]
        );
    }

    public function failed(\Throwable $exception): void
    {
        $purchase = Purchase::find($this->purchaseId);

        ActivityLog::record(
            'email.failed',
            "Voucher purchase email FAILED for purchase " . ($purchase->reference ?? $this->purchaseId) . ": {$exception->getMessage()}",
            $purchase && $purchase->customer_id ? ['customer_id' => $purchase->customer_id] : []
        );
    }
}
