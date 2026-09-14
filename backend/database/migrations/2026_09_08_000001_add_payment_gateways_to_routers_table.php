<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->boolean('momo_enabled')->default(true)->after('connection_mode');
            $table->boolean('paystack_enabled')->default(true)->after('momo_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['momo_enabled', 'paystack_enabled']);
        });
    }
};
