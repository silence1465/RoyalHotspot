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
        Schema::create('hotspot_users', function (Blueprint $table) {

            $table->id();

            $table->foreignId('customer_id')
                ->constrained();

            $table->foreignId('router_id')
                ->constrained();

            $table->string('mikrotik_user_id')
                ->nullable();

            $table->string('username');

            $table->string('password');

            $table->string('profile')
                ->nullable();

            $table->boolean('disabled')
                ->default(false);

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotspot_users');
    }
};
