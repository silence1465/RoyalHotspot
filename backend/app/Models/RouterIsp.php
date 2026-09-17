<?php

namespace App\Models;

use App\Services\CapacityService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RouterIsp extends Model
{
    use HasFactory;

    protected $fillable = [
        'router_id',
        'name',
        'wan_interface',
        'gateway',
        'routing_table',
        'connection_mark',
        'monthly_capacity_bytes',
        'subscriber_limit',
        'priority',
        'enabled',
        'session_monitoring_enabled',
        'session_protection_enabled',
        'stale_cleanup_enabled',
        'emergency_cleanup_enabled',
        'session_soft_limit',
        'session_hard_limit',
        'session_emergency_limit',
        'max_tcp_sessions_per_client',
        'max_udp_sessions_per_client',
        'max_total_sessions_per_client',
    ];

    protected $casts = [
        'monthly_capacity_bytes' => 'integer',
        'subscriber_limit' => 'integer',
        'priority' => 'integer',
        'enabled' => 'boolean',
        'session_monitoring_enabled' => 'boolean',
        'session_protection_enabled' => 'boolean',
        'stale_cleanup_enabled' => 'boolean',
        'emergency_cleanup_enabled' => 'boolean',
        'session_soft_limit' => 'integer',
        'session_hard_limit' => 'integer',
        'session_emergency_limit' => 'integer',
        'max_tcp_sessions_per_client' => 'integer',
        'max_udp_sessions_per_client' => 'integer',
        'max_total_sessions_per_client' => 'integer',
    ];

    protected $appends = ['capacity_summary'];

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function sessionSnapshots()
    {
        return $this->hasMany(IspSessionSnapshot::class, 'router_isp_id');
    }

    public function latestSessionSnapshot()
    {
        return $this->hasOne(IspSessionSnapshot::class, 'router_isp_id')->latestOfMany('recorded_at');
    }

    public function getCapacitySummaryAttribute(): array
    {
        return app(CapacityService::class)->summary($this);
    }
}
