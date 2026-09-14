<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_packages', function (Blueprint $table) {
            $table->string('usage_policy', 20)->default('none')->after('data_limit');
            $table->string('fup_period', 20)->default('cycle')->after('usage_policy');
            $table->unsignedBigInteger('data_allowance_bytes')->nullable()->after('fup_period');
            $table->unsignedTinyInteger('tier1_threshold_percent')->default(60)->after('data_allowance_bytes');
            $table->unsignedTinyInteger('tier2_threshold_percent')->default(85)->after('tier1_threshold_percent');
            $table->unsignedTinyInteger('tier1_speed_percent')->default(100)->after('tier2_threshold_percent');
            $table->unsignedTinyInteger('tier2_speed_percent')->default(70)->after('tier1_speed_percent');
            $table->unsignedTinyInteger('tier3_speed_percent')->default(30)->after('tier2_speed_percent');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->string('usage_policy', 20)->default('none')->after('admin_notes');
            $table->string('fup_period', 20)->default('cycle')->after('usage_policy');
            $table->string('base_speed_limit', 50)->nullable()->after('fup_period');
            $table->unsignedBigInteger('data_allowance_bytes')->nullable()->after('base_speed_limit');
            $table->unsignedTinyInteger('tier1_threshold_percent')->default(60)->after('data_allowance_bytes');
            $table->unsignedTinyInteger('tier2_threshold_percent')->default(85)->after('tier1_threshold_percent');
            $table->unsignedTinyInteger('tier1_speed_percent')->default(100)->after('tier2_threshold_percent');
            $table->unsignedTinyInteger('tier2_speed_percent')->default(70)->after('tier1_speed_percent');
            $table->unsignedTinyInteger('tier3_speed_percent')->default(30)->after('tier2_speed_percent');
            $table->unsignedBigInteger('cycle_bytes_used')->default(0)->after('tier3_speed_percent');
            $table->unsignedTinyInteger('current_fup_tier')->default(1)->after('cycle_bytes_used');
            $table->string('applied_speed_limit', 50)->nullable()->after('current_fup_tier');
            $table->timestamp('usage_policy_applied_at')->nullable()->after('applied_speed_limit');
            $table->string('policy_access_status', 20)->default('active')->after('usage_policy_applied_at');
        });

        Schema::create('monthly_capacity_adjustments', function (Blueprint $table) {
            $table->id();
            $table->date('month');
            $table->unsignedBigInteger('capacity_bytes');
            $table->unsignedTinyInteger('reserve_percent')->default(15);
            $table->text('reason');
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['month', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_capacity_adjustments');
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn([
                'usage_policy', 'fup_period', 'base_speed_limit', 'data_allowance_bytes',
                'tier1_threshold_percent', 'tier2_threshold_percent',
                'tier1_speed_percent', 'tier2_speed_percent', 'tier3_speed_percent',
                'cycle_bytes_used', 'current_fup_tier', 'applied_speed_limit',
                'usage_policy_applied_at', 'policy_access_status',
            ]);
        });
        Schema::table('internet_packages', function (Blueprint $table) {
            $table->dropColumn([
                'usage_policy', 'fup_period', 'data_allowance_bytes',
                'tier1_threshold_percent', 'tier2_threshold_percent',
                'tier1_speed_percent', 'tier2_speed_percent', 'tier3_speed_percent',
            ]);
        });
    }
};
