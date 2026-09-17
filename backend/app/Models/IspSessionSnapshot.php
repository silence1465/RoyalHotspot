<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IspSessionSnapshot extends Model
{
    protected $fillable = [
        'router_id', 'router_isp_id', 'tcp_sessions', 'udp_sessions',
        'total_sessions', 'unattributed_sessions', 'utilization_percent',
        'state', 'recorded_at',
    ];

    protected $casts = [
        'tcp_sessions' => 'integer',
        'udp_sessions' => 'integer',
        'total_sessions' => 'integer',
        'unattributed_sessions' => 'integer',
        'utilization_percent' => 'decimal:2',
        'recorded_at' => 'datetime',
    ];

    public function isp()
    {
        return $this->belongsTo(RouterIsp::class, 'router_isp_id');
    }
}
