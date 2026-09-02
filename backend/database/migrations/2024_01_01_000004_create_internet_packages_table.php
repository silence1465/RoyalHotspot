<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internet_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('duration_value');
            $table->enum('duration_unit', ['minutes', 'hours', 'days', 'weeks', 'months']);
            $table->string('speed_limit')->nullable(); // e.g. "5M/5M" RouterOS rate-limit format
            $table->string('data_limit')->nullable();  // e.g. "2G", null = unlimited
            // NOTE: no `mikrotik_profile` column here — a single global
            // profile name breaks once two routers name hotspot profiles
            // differently. See router_package_profiles pivot table instead.
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internet_packages');
    }
};
