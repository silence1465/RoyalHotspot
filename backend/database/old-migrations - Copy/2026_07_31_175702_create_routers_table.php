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
        Schema::create('routers', function (Blueprint $table) {

            $table->id();

            $table->string('name');

            $table->string('location');

            $table->ipAddress('router_ip');

            $table->ipAddress('wireguard_ip');

            $table->string('api_username');

            $table->text('api_password');

            $table->integer('api_port')->default(8729);

            $table->boolean('api_ssl')->default(true);

            $table->enum('status', [
                'online',
                'offline',
                'maintenance'
            ])->default('offline');

            $table->timestamps();

            $table->softDeletes();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('routers');
    }
};
