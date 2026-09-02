<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BandwidthLog extends Model
{
    protected $fillable = ['customer_id', 'router_id', 'date', 'bytes_in', 'bytes_out'];

    protected $casts = [
        'date' => 'date',
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
