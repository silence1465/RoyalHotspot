<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $fillable = [
        'customer_id', 'package_id', 'amount', 'reference', 'status',
        'voucher_id', 'verified_at', 'verification_method', 'verified_by',
        'momo_transaction_id', 'admin_notes', 'expires_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function package()
    {
        return $this->belongsTo(InternetPackage::class, 'package_id');
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function smsLogs()
    {
        return $this->hasMany(PaymentSmsLog::class, 'matched_order_id');
    }

    /**
     * Unique, unpredictable reference — same reasoning as
     * Voucher::generateCode() and PaystackService::generateReference():
     * never sequential/short, so it can't be guessed to claim someone
     * else's order. Format matches the spec's example (RW-A82K91).
     */
    public static function generateReference(): string
    {
        do {
            $reference = 'RW-' . strtoupper(Str::random(6));
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isPast();
    }
}
