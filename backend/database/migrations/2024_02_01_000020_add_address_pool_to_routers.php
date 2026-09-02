<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The RouterOS hotspot user profiles this app creates
 * (MikrotikService::ensureHotspotUserProfile(), called from package saves
 * and voucher generation) were being created with no address-pool at all,
 * leaving every profile to fall back on whatever the hotspot server
 * profile's own default happened to be. One pool per router — not per
 * package — matches the common case of a single hotspot network/subnet
 * serving every package on that router.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->string('address_pool')->nullable()->after('provisioning_api_password');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn('address_pool');
        });
    }
};
