<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('router_isps', function (Blueprint $table) {
            $table->string('connection_mark', 100)->nullable()->after('routing_table');
            $table->boolean('session_monitoring_enabled')->default(false)->after('enabled');
            $table->boolean('session_protection_enabled')->default(false)->after('session_monitoring_enabled');
            $table->boolean('stale_cleanup_enabled')->default(false)->after('session_protection_enabled');
            $table->boolean('emergency_cleanup_enabled')->default(false)->after('stale_cleanup_enabled');
            $table->unsignedInteger('session_soft_limit')->nullable()->after('emergency_cleanup_enabled');
            $table->unsignedInteger('session_hard_limit')->nullable()->after('session_soft_limit');
            $table->unsignedInteger('session_emergency_limit')->nullable()->after('session_hard_limit');
            $table->unsignedInteger('max_tcp_sessions_per_client')->nullable()->after('session_emergency_limit');
            $table->unsignedInteger('max_udp_sessions_per_client')->nullable()->after('max_tcp_sessions_per_client');
            $table->unsignedInteger('max_total_sessions_per_client')->nullable()->after('max_udp_sessions_per_client');
        });

        Schema::create('isp_session_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_isp_id')->constrained('router_isps')->cascadeOnDelete();
            $table->unsignedInteger('tcp_sessions')->default(0);
            $table->unsignedInteger('udp_sessions')->default(0);
            $table->unsignedInteger('total_sessions')->default(0);
            $table->unsignedInteger('unattributed_sessions')->default(0);
            $table->decimal('utilization_percent', 8, 2)->nullable();
            $table->string('state', 20)->default('normal');
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['router_isp_id', 'recorded_at']);
            $table->index(['router_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isp_session_snapshots');
        Schema::table('router_isps', function (Blueprint $table) {
            $table->dropColumn([
                'connection_mark', 'session_monitoring_enabled', 'session_protection_enabled',
                'stale_cleanup_enabled', 'emergency_cleanup_enabled', 'session_soft_limit',
                'session_hard_limit', 'session_emergency_limit', 'max_tcp_sessions_per_client',
                'max_udp_sessions_per_client', 'max_total_sessions_per_client',
            ]);
        });
    }
};
