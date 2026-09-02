<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            // Drop old FK
            if (Schema::hasColumn('vouchers', 'order_id')) {
                $table->dropForeign(['order_id']);
                $table->dropIndex(['order_id']);
                $table->dropColumn('order_id');
            }

            $table->foreignId('purchase_id')->nullable()->after('import_batch_id')
                ->constrained('purchases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropForeign(['purchase_id']);
            $table->dropColumn('purchase_id');

            $table->unsignedBigInteger('order_id')->nullable()->after('import_batch_id');
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
        });
    }
};
