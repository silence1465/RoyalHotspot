<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MikrotikLog extends Model
{
    protected $fillable = [
        'router_id', 'action', 'request_payload', 'response_payload', 'status', 'error_message',
    ];

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}
