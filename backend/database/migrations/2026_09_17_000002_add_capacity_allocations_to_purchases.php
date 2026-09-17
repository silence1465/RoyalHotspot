<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('router_isp_id')->nullable()->after('router_id')->constrained('router_isps')->nullOnDelete();
            $table->date('capacity_month')->nullable()->after('router_isp_id');
            $table->unsignedBigInteger('capacity_reserved_bytes')->default(0)->after('capacity_month');
            $table->unsignedBigInteger('rollover_bytes')->default(0)->after('capacity_reserved_bytes');
            $table->date('rollover_expires_on')->nullable()->after('rollover_bytes');
            $table->string('queue_reason', 32)->nullable()->after('status');
            $table->index(['router_isp_id', 'capacity_month', 'status'], 'purchases_isp_capacity_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex('purchases_isp_capacity_status_index');
            $table->dropConstrainedForeignId('router_isp_id');
            $table->dropColumn([
                'capacity_month', 'capacity_reserved_bytes', 'rollover_bytes',
                'rollover_expires_on', 'queue_reason',
            ]);
        });
    }
};
