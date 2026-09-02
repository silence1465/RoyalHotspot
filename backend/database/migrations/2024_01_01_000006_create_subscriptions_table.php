<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('internet_packages');
            $table->foreignId('router_id')->constrained();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            // `pending_activation` added beyond the original spec: payment
            // succeeded but the queued MikroTik job failed after retries —
            // surfaced to admins for manual retry (see API_SPEC.md).
            $table->enum('status', [
                'pending', 'active', 'expired', 'cancelled', 'suspended', 'pending_activation',
            ])->default('pending');
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            // subscriptions:expire scans WHERE status='active' AND
            // expires_at <= now() every minute — composite index keeps
            // that cheap as the table grows.
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
