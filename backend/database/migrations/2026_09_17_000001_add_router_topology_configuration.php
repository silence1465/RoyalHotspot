<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->string('routeros_version', 32)->nullable()->after('connection_mode');
        });

        Schema::create('router_isps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('wan_interface');
            $table->ipAddress('gateway');
            $table->string('routing_table')->default('main');
            $table->unsignedBigInteger('monthly_capacity_bytes')->nullable();
            $table->unsignedInteger('subscriber_limit')->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['router_id', 'name']);
            $table->unique(['router_id', 'wan_interface']);
            $table->index(['router_id', 'enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_isps');

        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn('routeros_version');
        });
    }
};
