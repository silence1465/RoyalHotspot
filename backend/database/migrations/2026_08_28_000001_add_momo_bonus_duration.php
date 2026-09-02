<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_packages', function (Blueprint $table) {
            $table->unsignedInteger('momo_bonus_days')->default(0)->after('duration_unit');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedInteger('bonus_duration_minutes')->default(0)->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', fn (Blueprint $table) => $table->dropColumn('bonus_duration_minutes'));
        Schema::table('internet_packages', fn (Blueprint $table) => $table->dropColumn('momo_bonus_days'));
    }
};
