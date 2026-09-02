<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HotspotSession extends Model
{
    protected $fillable = [
        'public_id', 'customer_id', 'purchase_id', 'router_id',
        'mikrotik_username', 'mikrotik_session_id', 'mac_address',
        'ip_address', 'status', 'disconnect_reason', 'started_at',
        'last_seen_at', 'ended_at', 'failure_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }
}
