<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotspotUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'router_id', 'mikrotik_user_id',
        'username', 'password', 'profile', 'disabled',
        'last_bytes_in', 'last_bytes_out', 'last_polled_at',
    ];

    protected $casts = [
        'disabled' => 'boolean',
        'last_polled_at' => 'datetime',
    ];

    // Hidden by default; the customer dashboard endpoint (Phase 7) should
    // explicitly append this attribute only when actively showing the
    // customer their own Wi-Fi credentials, not on any admin list view.
    protected $hidden = [
        'password',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }
}
