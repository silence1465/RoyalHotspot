<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 'queued' — payment is done, the purchase is fully valid, but
 * fulfillment (creating a live hotspot login or assigning a voucher
 * code) is deliberately held back because the customer already has an
 * active purchase on this same router. Shown in "My Vouchers" with an
 * Activate button — the customer decides when to actually use it, once
 * their current one runs out (or immediately, on a separate device —
 * see PurchaseController::activate()).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE purchases MODIFY status ENUM(
            'pending', 'processing', 'verified', 'active', 'voucher_assigned',
            'completed', 'pending_activation', 'manual_review', 'suspended',
            'failed', 'expired', 'cancelled', 'queued'
        ) DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE purchases MODIFY status ENUM(
            'pending', 'processing', 'verified', 'active', 'voucher_assigned',
            'completed', 'pending_activation', 'manual_review', 'suspended',
            'failed', 'expired', 'cancelled'
        ) DEFAULT 'pending'");
    }
};
