<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same category of pre-existing gap as 2024_02_01_000007 (queue tables):
 * CACHE_STORE=database has been set since Phase 1 and is actively relied
 * on by SystemSetting::get()/set() (Cache::rememberForever) and by the
 * 'login'/'voucher-redeem' rate limiters (Illuminate\Cache\RateLimiter
 * stores hit counts in the default cache store) — but nothing ever
 * created the underlying table. Fixed now because Phase 4 adds new
 * settings (momo_number, momo_account_name) that go through the exact
 * same SystemSetting::get() path.
 *
 * Guarded with hasTable() — same reasoning as 2024_02_01_000007: these
 * tables may already exist from a stock Laravel install or a manual
 * `php artisan cache:table` run, under a different migration name.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
