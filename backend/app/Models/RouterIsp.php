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
        'monthly_capacity_bytes',
        'subscriber_limit',
        'priority',
        'enabled',
    ];

    protected $casts = [
        'monthly_capacity_bytes' => 'integer',
        'subscriber_limit' => 'integer',
        'priority' => 'integer',
        'enabled' => 'boolean',
    ];

    protected $appends = ['capacity_summary'];

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function getCapacitySummaryAttribute(): array
    {
        return app(CapacityService::class)->summary($this);
    }
}
