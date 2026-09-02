<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE UNIFIED PURCHASE RECORD
 *
 * Replaces the old split between `subscriptions` (Paystack + live
 * router provisioning) and `orders` (MoMo + voucher assignment). Those
 * two tables existed because the original design assumed payment method
 * and fulfillment method were the same decision — Paystack always meant
 * live provisioning, MoMo always meant vouchers. That assumption turned
 * out to be wrong: payment method is a global toggle (Paystack XOR MoMo,
 * system-wide), and fulfillment is decided per-router (live → username/
 * password, manual → voucher code). Any combination is valid.
 *
 * This table carries columns from BOTH old tables, with two new fields
 * that make the old table-level split explicit as data instead:
 *   - payment_method: 'paystack' | 'momo' — how the money came in
 *   - fulfillment_type: 'live' | 'voucher' — how access was delivered
 *
 * The old `subscriptions` and `orders` tables are NOT dropped by this
 * migration — they're left in place so existing data isn't destroyed,
 * and a future migration can move historical rows into this table if
 * needed. All new code writes here; nothing reads the old tables anymore.
 *
 * `payments` table (Paystack transaction records) stays completely
 * separate — it's a financial transaction log, not a purchase lifecycle
 * record, and the Payments admin page still needs it independently.
 * Its `subscription_id` FK becomes `purchase_id` via a separate
 * migration (see 2024_02_01_000014).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();

            // Nullable for guest checkout (no account, MoMo-only, live
            // routers only — see guest checkout design in README).
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('package_id')->constrained('internet_packages');
            $table->foreignId('router_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('amount', 10, 2);
            $table->string('reference')->unique();

            // ── The two independent dimensions ──────────────────────────
            $table->enum('payment_method', ['paystack', 'momo'])->default('momo');
            $table->enum('fulfillment_type', ['live', 'voucher'])->default('voucher');

            // ── Unified status covering both lifecycles ─────────────────
            // From subscriptions: pending, active, expired, suspended,
            //   cancelled, pending_activation
            // From orders: pending, processing, verified, voucher_assigned,
            //   completed, failed, expired, cancelled, manual_review
            // Merged set (no duplicates):
            $table->enum('status', [
                'pending',              // created, awaiting payment
                'processing',           // payment signal received, checking
                'verified',             // payment confirmed, not yet fulfilled
                'active',               // live-fulfilled: hotspot user created and working
                'voucher_assigned',     // voucher-fulfilled: code handed out
                'completed',            // terminal success (either kind)
                'pending_activation',   // live-fulfilled: payment OK but MikroTik call failed
                'manual_review',        // ambiguous payment match, needs human
                'suspended',            // admin hold (live: hotspot disabled; voucher: record-only)
                'failed',               // payment verification failed
                'expired',              // pending too long without payment
                'cancelled',            // admin or customer cancelled
            ])->default('pending');

            // ── Live fulfillment fields (from subscriptions) ────────────
            $table->timestamp('starts_at')->nullable();

            // ── Voucher fulfillment fields (from orders) ────────────────
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();

            // ── Payment verification (from orders, applicable to both) ──
            $table->timestamp('verified_at')->nullable();
            $table->enum('verification_method', ['sms_auto', 'manual_transaction_id', 'admin_manual', 'paystack_webhook'])->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('momo_transaction_id')->nullable();

            // ── Shared fields ───────────────────────────────────────────
            $table->timestamp('expires_at')->nullable();
            $table->text('admin_notes')->nullable();

            // ── Guest checkout fields ───────────────────────────────────
            $table->string('guest_phone')->nullable();
            $table->string('guest_code', 6)->nullable();

            $table->timestamps();

            // ── Indexes ─────────────────────────────────────────────────
            $table->index('status');
            $table->index(['status', 'expires_at']);
            $table->index('momo_transaction_id');
            $table->index('guest_phone');
            $table->index('fulfillment_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
