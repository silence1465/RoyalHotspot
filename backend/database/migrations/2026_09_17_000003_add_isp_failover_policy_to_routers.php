<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->boolean('isp_failover_enabled')->default(false)->after('routeros_version');
            $table->boolean('isp_failback_enabled')->default(true)->after('isp_failover_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['isp_failover_enabled', 'isp_failback_enabled']);
        });
    }
};
