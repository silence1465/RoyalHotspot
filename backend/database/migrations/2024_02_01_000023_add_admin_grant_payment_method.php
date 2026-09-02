<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Raw SQL rather than Schema::table()->enum() — MySQL requires the full
 * enum value list on any modification, and this project avoids
 * doctrine/dbal (see 2024_02_01_000012 for the same reasoning).
 *
 * 'admin_grant' — an admin assigning a package to a customer without
 * payment. amount is recorded as 0 for these (see
 * Admin\PurchaseController::assign()), which also keeps them correctly
 * out of every revenue total that sums by amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE purchases MODIFY payment_method ENUM('paystack', 'momo', 'admin_grant') DEFAULT 'momo'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE purchases MODIFY payment_method ENUM('paystack', 'momo') DEFAULT 'momo'");
    }
};
