<?php

namespace App\Models;

use App\Support\AdminPermissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'role', 'permissions', 'status', 'two_factor_secret', 'two_factor_confirmed_at',
    ];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'two_factor_secret' => 'encrypted',
        'two_factor_confirmed_at' => 'datetime',
        'permissions' => 'array',
    ];

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function generatedVouchers()
    {
        return $this->hasMany(Voucher::class, 'generated_by');
    }

    // Royal WiFi extension
    public function uploadedVoucherBatches()
    {
        return $this->hasMany(VoucherImportBatch::class, 'uploaded_by');
    }

    public function verifiedPurchases()
    {
        return $this->hasMany(Purchase::class, 'verified_by');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function routers()
    {
        return $this->belongsToMany(Router::class, 'admin_router')->withTimestamps();
    }

    public function hasPermission(string $permission): bool
    {
        // Existing admin rows predate granular RBAC. A NULL value preserves
        // their former access; newly managed accounts always store an array.
        return $this->isSuperAdmin()
            || ($this->role === 'admin' && $this->permissions === null)
            || in_array($permission, $this->permissions ?? [], true);
    }

    public function effectivePermissions(): array
    {
        return ($this->isSuperAdmin() || ($this->role === 'admin' && $this->permissions === null))
            ? AdminPermissions::ALL
            : ($this->permissions ?? []);
    }

    public function canAccessRouter(int $routerId): bool
    {
        return $this->isSuperAdmin() || $this->routers()->whereKey($routerId)->exists();
    }
}
