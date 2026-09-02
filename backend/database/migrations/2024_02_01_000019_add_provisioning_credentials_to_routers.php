<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The existing api_username/api_password pair is deliberately
 * least-privilege (see docs/PRODUCTION_SECURITY.md) — day-to-day
 * hotspot user create/enable/disable only, /tool fetch and file
 * operations explicitly denied in that user's RouterOS group policy, so
 * a leaked credential can't be used to push arbitrary files onto the
 * router.
 *
 * "Set Up Guest Portal" needs exactly the permissions that policy was
 * written to deny (/tool fetch, file write) to download our login.html
 * onto the router. Rather than widen the existing user, this is a
 * SECOND, separate credential — used only for that one provisioning
 * action. If it ever leaked, the blast radius is "can rewrite a login
 * page", not "can also touch hotspot billing directly".
 *
 * Both nullable: only needed on a live router where the admin actually
 * wants to use the automated portal setup. A manual router, or a live
 * router where the admin sets this up by hand in Winbox instead, never
 * needs this filled in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->string('provisioning_api_username')->nullable()->after('api_ssl');
            $table->text('provisioning_api_password')->nullable()->after('provisioning_api_username');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['provisioning_api_username', 'provisioning_api_password']);
        });
    }
};
