<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotspot_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->string('mikrotik_user_id')->nullable(); // RouterOS .id of the hotspot user
            $table->string('username');
            // Hotspot-only secret, randomly generated — unrelated to the
            // customer's account login password (see review note).
            $table->string('password');
            $table->string('profile')->nullable();
            $table->boolean('disabled')->default(false);
            $table->timestamps();

            $table->unique(['customer_id', 'router_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotspot_users');
    }
};
