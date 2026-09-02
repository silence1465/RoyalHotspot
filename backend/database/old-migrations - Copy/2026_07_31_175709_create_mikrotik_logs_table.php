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
        Schema::create('mikrotik_logs', function (Blueprint $table) {

            $table->id();

            $table->foreignId('router_id')
                ->constrained();

            $table->string('action');

            $table->longText('request_payload')
                ->nullable();

            $table->longText('response_payload')
                ->nullable();

            $table->enum('status',[
                'success',
                'failed'
            ]);

            $table->text('error_message')
                ->nullable();

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mikrotik_logs');
    }
};
