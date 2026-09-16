<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
        });

        Schema::create('admin_router', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'router_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_router');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('permissions'));
    }
};
