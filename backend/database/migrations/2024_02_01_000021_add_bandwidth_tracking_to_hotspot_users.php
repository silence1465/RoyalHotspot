<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RouterOS reports bytes-in/bytes-out as CUMULATIVE totals for the
 * current session, not deltas since the last check. To build a
 * historical daily bandwidth log without double-counting or
 * misattributing a session that spans midnight, each poll needs to know
 * "what was the cumulative total last time I checked" so it can compute
 * just the increment and add that to today's log — see
 * SnapshotBandwidthUsage command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotspot_users', function (Blueprint $table) {
            $table->unsignedBigInteger('last_bytes_in')->default(0)->after('disabled');
            $table->unsignedBigInteger('last_bytes_out')->default(0)->after('last_bytes_in');
            $table->timestamp('last_polled_at')->nullable()->after('last_bytes_out');
        });
    }

    public function down(): void
    {
        Schema::table('hotspot_users', function (Blueprint $table) {
            $table->dropColumn(['last_bytes_in', 'last_bytes_out', 'last_polled_at']);
        });
    }
};
