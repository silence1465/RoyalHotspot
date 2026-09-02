<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentSmsLog extends Model
{
    protected $fillable = [
        'raw_body', 'sender', 'recipient', 'transaction_id', 'amount',
        'parsed_phone', 'parsed_reference', 'received_at',
        'matched_purchase_id', 'verification_status', 'forwarder_meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'received_at' => 'datetime',
        'forwarder_meta' => 'array',
    ];

    public function matchedPurchase()
    {
        return $this->belongsTo(Purchase::class, 'matched_purchase_id');
    }

    /**
     * Backward-compatible API relation name used by the admin SMS log UI.
     * Records now point to the unified purchases table.
     */
    public function matchedOrder()
    {
        return $this->matchedPurchase();
    }

    public function scopeUnmatched($query)
    {
        return $query->where('verification_status', 'unmatched');
    }
}
