<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InternetPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'description', 'price', 'duration_value', 'duration_unit', 'momo_bonus_value', 'momo_bonus_unit',
        'speed_limit', 'data_limit', 'status', 'sales_channel', 'available_to_guests',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'available_to_guests' => 'boolean',
        'momo_bonus_value' => 'integer',
    ];

    public function purchases()
    {
        return $this->hasMany(Purchase::class, 'package_id');
    }

    public function routerProfiles()
    {
        return $this->hasMany(RouterPackageProfile::class, 'package_id');
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class, 'package_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function momoBonusMinutes(): int
    {
        $multiplier = $this->momo_bonus_unit === 'hours' ? 60 : 1440;

        return (int) $this->momo_bonus_value * $multiplier;
    }
}
