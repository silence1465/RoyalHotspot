<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('phone')->unique();
            $table->string('email')->nullable();
            $table->string('username')->unique();
            $table->string('password'); // hashed account login password
            // NOTE: `password_text` intentionally NOT included — see
            // docs/DATABASE_SCHEMA.md. The hotspot Wi-Fi password is a
            // separate random secret stored only in hotspot_users.password.
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('inactive');
            // FK constraint added in a later migration once the
            // subscriptions table exists (see 2024_01_01_000013_...).
            $table->unsignedBigInteger('current_subscription_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('current_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
