<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * internet_packages is now shared by two customer-facing storefronts:
 * the original Paystack "Buy Internet" flow (GET /customer/packages,
 * router-subscription based) and the new Royal WiFi "Buy Voucher" flow
 * (both now surfaced via GET /customer/packages, PDF-imported-code based
 * for voucher sales_channel). Without
 * something distinguishing them, a Royal WiFi package would also appear
 * in the old subscription storefront and vice versa — genuinely
 * confusing, since they lead to completely different purchase flows.
 *
 * Default 'subscription' preserves EXACT current behavior for every
 * existing row — nothing changes for the original flow unless an admin
 * explicitly creates/edits a package as 'voucher' or 'both'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_packages', function (Blueprint $table) {
            $table->enum('sales_channel', ['subscription', 'voucher', 'both'])
                ->default('subscription')
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('internet_packages', function (Blueprint $table) {
            $table->dropColumn('sales_channel');
        });
    }
};
