<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {

            $table->id();

            $table->string('code')
                ->unique();

            $table->foreignId('package_id')
                ->constrained('internet_packages');

            $table->foreignId('router_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->enum('status',[
                'unused',
                'used',
                'expired'
            ]);

            $table->foreignId('used_by_customer_id')
                ->nullable()
                ->constrained('customers')
                ->nullOnDelete();

            $table->timestamp('used_at')
                ->nullable();

            $table->timestamp('expires_at')
                ->nullable();

            $table->uuid('batch_id')
                ->nullable();

            $table->foreignId('generated_by')
                ->constrained('users');

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
