<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Royal WiFi voucher system extension — see docs/DATABASE_SCHEMA.md
 * "Royal WiFi Extension" section for the full reasoning.
 *
 * The spec's requested `packages` table (id, name, price, duration_days,
 * description, status) is intentionally NOT created as a new table —
 * `internet_packages` already covers name/price/duration/status. The
 * only gap is `description`, added here. `duration_days` maps onto the
 * existing `duration_value` + `duration_unit` pair (set duration_unit to
 * 'days' for Royal WiFi voucher packages).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_packages', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('internet_packages', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
