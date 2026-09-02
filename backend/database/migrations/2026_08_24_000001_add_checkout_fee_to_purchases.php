<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('subtotal', 10, 2)->default(0)->after('router_id');
            $table->decimal('payment_fee', 10, 2)->default(0)->after('subtotal');
        });

        DB::table('purchases')->update(['subtotal' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'payment_fee']);
        });
    }
};
