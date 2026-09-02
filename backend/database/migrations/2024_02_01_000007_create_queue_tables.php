<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standard Laravel queue schema. This is a genuine pre-existing gap, not
 * a Royal WiFi addition — `QUEUE_CONNECTION=database` has been set since
 * Phase 1 and ActivateHotspotUserJob has been dispatched since Phase 9,
 * but nothing ever created the table those dispatches write to. Every
 * queued job in the project has had nowhere to actually go. Fixed here
 * because it directly affects existing MikroTik provisioning (the exact
 * kind of pre-existing bug worth fixing while touching this area) and
 * because Phase 4 adds a new queued job (voucher purchase email) that
 * would hit the identical problem.
 *
 * Guarded with hasTable() checks: if this project was ever bootstrapped
 * via `laravel new`, or if `php artisan queue:table` was run manually at
 * any point, these tables may already exist under a differently-named
 * migration that Laravel's migration tracker has no way of knowing is
 * redundant with this one. Skipping instead of failing makes this safe
 * either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
