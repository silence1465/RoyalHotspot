<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The RouterOS .id of the hotspot user created for this voucher code at
 * generation time (Admin\VoucherController::generate()) — previously
 * thrown away, meaning nothing could find and disable the actual account
 * a redeemed voucher corresponds to once its access window expired. See
 * PurchaseService::expirePurchase()'s voucher branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->string('mikrotik_user_id')->nullable()->after('router_id');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn('mikrotik_user_id');
        });
    }
};
