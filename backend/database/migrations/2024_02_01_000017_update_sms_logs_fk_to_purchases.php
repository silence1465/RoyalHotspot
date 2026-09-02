<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_sms_logs', function (Blueprint $table) {
            if (Schema::hasColumn('payment_sms_logs', 'matched_order_id')) {
                $table->dropForeign(['matched_order_id']);
                $table->dropColumn('matched_order_id');
            }

            $table->foreignId('matched_purchase_id')->nullable()->after('received_at')
                ->constrained('purchases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_sms_logs', function (Blueprint $table) {
            $table->dropForeign(['matched_purchase_id']);
            $table->dropColumn('matched_purchase_id');

            $table->foreignId('matched_order_id')->nullable()->after('received_at')
                ->constrained('orders')->nullOnDelete();
        });
    }
};
