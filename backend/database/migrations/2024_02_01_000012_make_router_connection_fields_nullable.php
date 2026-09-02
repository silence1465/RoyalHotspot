<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * These three columns were NOT NULL from the original routers migration,
 * back when every router was assumed to be live-reachable. A router in
 * Manual mode (see 2024_02_01_000011_add_connection_mode_to_routers_table)
 * has none of this — making them nullable lets an admin create a manual
 * router with just a name/location, no fake connection details required
 * just to satisfy a NOT NULL constraint.
 *
 * Live routers are unaffected: RouterRequest still requires these fields
 * whenever connection_mode is 'live' (application-level validation), this
 * migration only removes the database-level requirement so a manual
 * router can legitimately have them blank.
 *
 * Raw SQL rather than Laravel's Schema::change() — that helper requires
 * the doctrine/dbal package, which isn't a dependency of this project.
 * Same reasoning as the enum-widening migration (2024_02_01_000003).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE routers MODIFY wireguard_ip VARCHAR(255) NULL');
        DB::statement('ALTER TABLE routers MODIFY api_username VARCHAR(255) NULL');
        DB::statement('ALTER TABLE routers MODIFY api_password TEXT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Deliberately not reversed automatically — if any manual router
        // has NULLs in these columns by the time this rolls back, the
        // NOT NULL constraint would fail. Set real values first if you
        // ever need to roll this back.
        DB::statement('ALTER TABLE routers MODIFY wireguard_ip VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE routers MODIFY api_username VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE routers MODIFY api_password TEXT NOT NULL');
    }
};
