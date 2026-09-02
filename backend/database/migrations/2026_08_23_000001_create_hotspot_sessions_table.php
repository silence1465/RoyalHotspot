<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotspot_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->string('mikrotik_username');
            $table->string('mikrotik_session_id')->nullable();
            $table->string('mac_address', 17)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('status', 24)->default('connecting');
            $table->string('disconnect_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'router_id', 'status']);
            $table->index(['router_id', 'mikrotik_username', 'status']);
            $table->index(['mac_address', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotspot_sessions');
    }
};
