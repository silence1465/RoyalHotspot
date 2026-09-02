<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Router extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'location', 'router_ip', 'wireguard_ip',
        'api_username', 'api_password', 'api_port', 'api_ssl', 'status', 'connection_mode',
        'provisioning_api_username', 'provisioning_api_password', 'address_pool',
        'hotspot_login_host',
    ];

    protected $hidden = [
        'api_password', // never serialize to JSON, even encrypted
        'provisioning_api_password',
    ];

    protected $casts = [
        // Laravel decrypts/encrypts transparently on read/write. Still,
        // MikrotikService is the only place that should ever call
        // decrypt() explicitly / read this attribute for actual use.
        'api_password' => 'encrypted',
        'provisioning_api_password' => 'encrypted',
        'api_ssl' => 'boolean',
    ];

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function hotspotUsers()
    {
        return $this->hasMany(HotspotUser::class);
    }

    public function packageProfiles()
    {
        return $this->hasMany(RouterPackageProfile::class);
    }

    public function mikrotikLogs()
    {
        return $this->hasMany(MikrotikLog::class);
    }

    public function hotspotSessions()
    {
        return $this->hasMany(HotspotSession::class);
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class);
    }

    /**
     * Resolve the RouterOS profile name for a given package on this
     * specific router (see router_package_profiles pivot table).
     */
    public function profileNameFor(InternetPackage $package): ?string
    {
        return $this->packageProfiles()
            ->where('package_id', $package->id)
            ->value('profile_name');
    }

    /**
     * True when this router has no live VPS/WireGuard connectivity —
     * every MikrotikService call short-circuits without attempting a
     * connection (see MikrotikService::run()). Set by an admin per
     * router, not a system-wide toggle, since different routers can
     * genuinely have different connectivity at different times.
     */
    public function isManual(): bool
    {
        return $this->connection_mode === 'manual';
    }
}
