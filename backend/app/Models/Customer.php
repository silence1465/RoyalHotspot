<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Extends Authenticatable (not a plain Model) so it can back its own
 * Sanctum guard, separate from the admin `users` guard — see
 * docs/API_SPEC.md for the config/auth.php guard setup this requires.
 */
class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'full_name', 'phone', 'email', 'username', 'password', 'status', 'current_purchase_id',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function currentPurchase()
    {
        return $this->belongsTo(Purchase::class, 'current_purchase_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function hotspotUsers()
    {
        return $this->hasMany(HotspotUser::class);
    }

    public function hotspotSessions()
    {
        return $this->hasMany(HotspotSession::class);
    }

    public function redeemedVouchers()
    {
        return $this->hasMany(Voucher::class, 'used_by_customer_id');
    }

    public function assignedVouchers()
    {
        return $this->hasMany(Voucher::class, 'assigned_to');
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function complaints()
    {
        return $this->hasMany(Complaint::class);
    }
}
