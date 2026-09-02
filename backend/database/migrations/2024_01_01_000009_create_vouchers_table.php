<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // long random — never sequential/short
            $table->foreignId('package_id')->constrained('internet_packages');
            $table->foreignId('router_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['unused', 'used', 'expired'])->default('unused');
            $table->foreignId('used_by_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            // Groups a bulk-generation run for export/audit.
            $table->uuid('batch_id')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
