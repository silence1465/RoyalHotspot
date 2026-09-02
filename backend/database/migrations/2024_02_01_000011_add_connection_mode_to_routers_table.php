<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original system assumed every router is always reachable over
 * WireGuard from a VPS. That assumption no longer holds when there's no
 * VPS at all — this column lets an admin explicitly mark a router as
 * 'manual' (no live API access, exactly like the PDF-import voucher flow
 * already assumes) instead of every live MikroTik call silently timing
 * out against a router that was never going to answer.
 *
 * Default 'live' preserves exact current behavior for every existing
 * router — nothing changes until an admin explicitly flips one to manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->enum('connection_mode', ['live', 'manual'])->default('live')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn('connection_mode');
        });
    }
};
