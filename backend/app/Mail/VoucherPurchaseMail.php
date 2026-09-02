<?php

namespace App\Mail;

use App\Models\Purchase;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VoucherPurchaseMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Purchase $purchase)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('Your Royal WiFi Voucher — ' . $this->purchase->reference)
            ->view('emails.voucher-purchase')
            ->with([
                'order' => $this->purchase, // template still uses $order for now
                'voucher' => $this->purchase->voucher,
                'package' => $this->purchase->package,
            ]);
    }
}
