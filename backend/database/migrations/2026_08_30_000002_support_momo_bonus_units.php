<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_packages', function (Blueprint $table) {
            $table->renameColumn('momo_bonus_days', 'momo_bonus_value');
        });

        Schema::table('internet_packages', function (Blueprint $table) {
            $table->string('momo_bonus_unit', 10)->default('days')->after('momo_bonus_value');
        });
    }

    public function down(): void
    {
        Schema::table('internet_packages', function (Blueprint $table) {
            $table->dropColumn('momo_bonus_unit');
        });

        Schema::table('internet_packages', function (Blueprint $table) {
            $table->renameColumn('momo_bonus_value', 'momo_bonus_days');
        });
    }
};
