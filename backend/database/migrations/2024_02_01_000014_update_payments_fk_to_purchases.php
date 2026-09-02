<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `payments.subscription_id` → `payments.purchase_id`. The payments
 * table itself stays unchanged as a financial transaction log — only
 * its FK pointer changes to reference the new unified purchases table
 * instead of the old subscriptions table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Drop old FK first
            $table->dropForeign(['subscription_id']);
            $table->dropColumn('subscription_id');

            // Add new one
            $table->foreignId('purchase_id')->nullable()->after('customer_id')
                ->constrained('purchases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['purchase_id']);
            $table->dropColumn('purchase_id');

            $table->foreignId('subscription_id')->nullable()->after('customer_id')
                ->constrained('subscriptions')->nullOnDelete();
        });
    }
};
