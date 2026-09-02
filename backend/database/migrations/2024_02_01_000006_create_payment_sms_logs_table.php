<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every SMS the forwarder app relays gets stored here, matched or not.
 * This is deliberately NOT folded into `orders` or `payments` — an SMS
 * can arrive with no matching order at all (wrong reference, arrives
 * before the order exists, spam), and the spec is explicit that
 * "NEVER mark an order paid merely because the customer submitted a
 * reference" — there must be an actual stored SMS (or admin approval) as
 * evidence. This table IS that evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_sms_logs', function (Blueprint $table) {
            $table->id();
            $table->text('raw_body');
            $table->string('sender')->nullable();
            $table->string('recipient')->nullable(); // the MoMo number that received it

            // Parsed out of raw_body by the matching service (Phase 4) —
            // nullable because parsing can fail on an unexpected SMS format,
            // and the raw SMS should still be stored even then.
            $table->string('transaction_id')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('parsed_phone')->nullable();
            $table->string('parsed_reference')->nullable();

            // Same MySQL strict-mode fix as orders.expires_at — see that
            // migration's comment. PaymentSmsWebhookController always sets
            // this explicitly from the SMS Forwarder's timestamp (or now()
            // as a fallback) on insert; this default is never relied on.
            $table->timestamp('received_at')->useCurrent();

            $table->foreignId('matched_order_id')->nullable()->constrained('orders')->nullOnDelete();

            $table->enum('verification_status', ['unmatched', 'matched', 'manual_review', 'duplicate'])
                ->default('unmatched');

            // Raw payload from the SMS Forwarder app (whatever it sends
            // beyond the SMS text itself — device ID, forwarder app
            // version, etc.) kept for debugging without a schema change
            // every time the forwarder's payload shape changes slightly.
            $table->json('forwarder_meta')->nullable();

            $table->timestamps();

            $table->index('transaction_id');
            $table->index('parsed_reference');
            $table->index('verification_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_sms_logs');
    }
};
