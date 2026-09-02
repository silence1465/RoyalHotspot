<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'purchase_id', 'reference', 'amount', 'currency',
        'channel', 'status', 'provider', 'provider_response', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    // provider_response can be large and contains no secrets worth hiding
    // from admins, but there's no reason to send it to the customer-facing
    // API by default.
    protected $hidden = [
        'provider_response',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'successful');
    }
}
