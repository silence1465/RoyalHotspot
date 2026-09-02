<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FreeTrialCampaign extends Model
{
    protected $fillable = ['name', 'package_id', 'router_id', 'starts_at', 'ends_at', 'is_active'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function package() { return $this->belongsTo(InternetPackage::class, 'package_id'); }
    public function router() { return $this->belongsTo(Router::class); }
    public function purchases() { return $this->hasMany(Purchase::class); }

    public function isOpen(): bool
    {
        return $this->is_active && now()->between($this->starts_at, $this->ends_at);
    }
}
