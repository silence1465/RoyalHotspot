<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHY A NEW TABLE, NOT A REUSE OF `payments`/`subscriptions`:
 *
 * The existing `payments` + `subscriptions` pair is shaped specifically
 * around the Paystack flow — a subscription always implies a router +
 * MikroTik hotspot user gets provisioned on activation. A Royal WiFi
 * voucher order never provisions anything on a router; it hands over a
 * code that already exists on MikroTik. Bolting MoMo/SMS-specific
 * columns (verification_method, momo_transaction_id, admin approval
 * fields) onto `payments`, and a voucher-assignment state machine onto
 * `subscriptions`, would mean every row in both tables carries a pile of
 * nullable columns that only make sense for one of the two flows. A
 * dedicated `orders` table keeps both flows readable on their own terms.
 *
 * `orders` is the direct implementation of the spec's "payment
 * reference belongs to one order" requirement — reference and order are
 * 1:1 by construction (reference lives ON the order row, not a separate
 * table), so "a reference must belong to exactly one order" is
 * structurally guaranteed rather than something application code has to
 * enforce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('package_id')->constrained('internet_packages');

            // Snapshot at order time — protects against a package price
            // edit changing what an in-flight order is expected to pay.
            $table->decimal('amount', 10, 2);

            // Unique, unpredictable — see Order::generateReference().
            // Long enough that it isn't guessable, short enough a
            // customer can type it into a MoMo payment note by hand.
            $table->string('reference')->unique();

            $table->enum('status', [
                'pending',          // order created, awaiting payment
                'processing',       // a payment signal arrived, being checked
                'verified',         // payment confirmed, voucher not yet assigned
                'voucher_assigned', // voucher locked and handed to this order
                'completed',        // terminal success (voucher assigned + customer notified)
                'failed',           // payment could not be verified
                'expired',          // pending too long, auto-expired
                'cancelled',        // customer or admin cancelled before payment
                'manual_review',    // ambiguous match, needs a human
            ])->default('pending');

            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();

            $table->timestamp('verified_at')->nullable();
            $table->enum('verification_method', ['sms_auto', 'manual_transaction_id', 'admin_manual'])->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete(); // set only for admin_manual

            // The transaction ID actually matched against — whether found
            // via SMS auto-match or customer-submitted "Already Paid"
            // fallback. NOT proof by itself (see PaymentSmsLog) — this is
            // a denormalized pointer to what was matched, for fast display.
            $table->string('momo_transaction_id')->nullable();

            $table->text('admin_notes')->nullable();

            // Pending orders don't stay claimable forever — protects
            // voucher inventory from being implicitly reserved by
            // abandoned orders. Actual expiry sweep is a scheduled
            // command (mirrors subscriptions:expire).
            // ->useCurrent() here is purely to satisfy MySQL strict mode,
            // which rejects a NOT NULL timestamp column with no explicit
            // default (error 1067). The application always sets a real
            // expires_at explicitly on insert (see
            // Customer\OrderController::store()) — this default is never
            // actually relied on in practice.
            $table->timestamp('expires_at')->useCurrent();

            $table->timestamps();

            $table->index('status');
            $table->index(['status', 'expires_at']); // for the expiry sweep, same reasoning as subscriptions
            $table->index('momo_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
