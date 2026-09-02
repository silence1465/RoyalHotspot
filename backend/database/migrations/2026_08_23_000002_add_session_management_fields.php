<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->string('hotspot_login_host')->nullable()->after('address_pool');
        });

        Schema::table('router_package_profiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('shared_users')->default(1)->after('profile_name');
        });
    }

    public function down(): void
    {
        Schema::table('router_package_profiles', function (Blueprint $table) {
            $table->dropColumn('shared_users');
        });

        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn('hotspot_login_host');
        });
    }
};
