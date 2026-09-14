<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyCapacityAdjustment extends Model
{
    protected $fillable = ['month', 'capacity_bytes', 'reserve_percent', 'reason', 'adjusted_by'];

    protected $casts = [
        'month' => 'date',
        'capacity_bytes' => 'integer',
        'reserve_percent' => 'integer',
    ];

    public function administrator()
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    public static function currentForMonth($month): ?self
    {
        return static::whereDate('month', $month->copy()->startOfMonth()->toDateString())
            ->latest('id')
            ->first();
    }
}
