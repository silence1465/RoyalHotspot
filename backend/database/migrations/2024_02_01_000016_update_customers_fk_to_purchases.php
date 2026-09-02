<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'current_subscription_id')) {
                $table->dropForeign(['current_subscription_id']);
                $table->dropIndex(['current_subscription_id']);
                $table->dropColumn('current_subscription_id');
            }

            $table->foreignId('current_purchase_id')->nullable()->after('status')
                ->constrained('purchases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['current_purchase_id']);
            $table->dropColumn('current_purchase_id');

            $table->foreignId('current_subscription_id')->nullable()->after('status')
                ->constrained('subscriptions')->nullOnDelete();
        });
    }
};
