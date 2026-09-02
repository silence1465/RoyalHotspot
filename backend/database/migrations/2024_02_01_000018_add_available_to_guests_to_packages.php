<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-package admin control over guest checkout eligibility. Guest
 * checkout is live-router-only (see guest checkout design), MoMo-only,
 * and results in a server-generated single-field code rather than a
 * pulled-from-inventory voucher — a package needs to be explicitly
 * opted in, not every package makes sense to sell to someone who'll
 * never have an account or dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_packages', function (Blueprint $table) {
            $table->boolean('available_to_guests')->default(false)->after('sales_channel');
        });
    }

    public function down(): void
    {
        Schema::table('internet_packages', function (Blueprint $table) {
            $table->dropColumn('available_to_guests');
        });
    }
};
