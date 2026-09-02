<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_package_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('internet_packages')->cascadeOnDelete();
            $table->string('profile_name'); // actual RouterOS hotspot profile name on this router
            $table->timestamps();

            $table->unique(['router_id', 'package_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_package_profiles');
    }
};
