<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Extends the EXISTING vouchers table rather than creating a parallel
 * one. Two things happen here:
 *
 * 1. New columns for the PDF-import flow: `import_batch_id` (which PDF
 *    upload this code came from — separate from the existing `batch_id`
 *    UUID used by the original in-app "generate" flow, never both),
 *    `order_id` (which Royal WiFi order claimed this code — FK added in
 *    a later migration once the `orders` table exists, same deferred-FK
 *    pattern as 2024_01_01_000013), `assigned_to`/`assigned_at` (see
 *    note below on why these are NOT the same as the existing
 *    `used_by_customer_id`/`used_at`), and `amount`/`duration_days`
 *    snapshots (so a voucher's sold price/duration doesn't silently
 *    change if an admin edits the package later).
 *
 * 2. Widening the `status` enum from ('unused','used','expired') to
 *    ('available','reserved','assigned','used','expired','invalid').
 *    Done as a 3-step ALTER (widen → migrate data → narrow) so existing
 *    'unused' rows become 'available' instead of erroring or truncating
 *    — never destroys existing data. See docs/DATABASE_SCHEMA.md.
 *
 * WHY assigned_to/assigned_at are separate from used_by_customer_id/used_at:
 * the original voucher flow (Customer\VoucherController::redeem) treats
 * redemption as immediate full usage — MikroTik provisioning happens
 * right then, so "redeemed" and "used" are the same instant. Royal WiFi
 * vouchers are different: the MikroTik code already existed before the
 * app ever saw it, so "assigned to a customer via a paid order" and
 * "actually used to log into the hotspot" are genuinely different
 * moments — the app can only ever be certain about the first one unless
 * it later polls MikroTik for usage (explicitly "optional future" in the
 * spec). used_by_customer_id/used_at are left untouched for the existing
 * flow; assigned_to/assigned_at are new and used only by the Royal WiFi
 * order-assignment path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->after('batch_id')
                ->constrained('voucher_import_batches')->nullOnDelete();

            // FK constraint added later once `orders` exists (deferred FK
            // pattern, see 2024_01_01_000013 for precedent).
            $table->unsignedBigInteger('order_id')->nullable()->after('import_batch_id');

            $table->foreignId('assigned_to')->nullable()->after('used_by_customer_id')
                ->constrained('customers')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_to');

            // Snapshots — see class docblock for why these aren't just
            // read live off internet_packages.
            $table->decimal('amount', 10, 2)->nullable()->after('assigned_at');
            $table->unsignedInteger('duration_days')->nullable()->after('amount');

            $table->index('order_id');
            $table->index('status');
        });

        // ── Widen the status enum, preserving existing data ──────────
        // Step 1: widen to a superset containing BOTH old and new values.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE vouchers MODIFY status ENUM('unused','available','reserved','assigned','used','expired','invalid') NOT NULL DEFAULT 'available'");
        }

        // Step 2: migrate existing data to the new vocabulary.
        DB::table('vouchers')->where('status', 'unused')->update(['status' => 'available']);

        // Step 3: narrow to the final set now that no row uses 'unused'.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE vouchers MODIFY status ENUM('available','reserved','assigned','used','expired','invalid') NOT NULL DEFAULT 'available'");
        }
    }

    public function down(): void
    {
        // Reverse the enum widening the same safe way.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE vouchers MODIFY status ENUM('unused','available','reserved','assigned','used','expired','invalid') NOT NULL DEFAULT 'available'");
        }
        DB::table('vouchers')->where('status', 'available')->update(['status' => 'unused']);
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE vouchers MODIFY status ENUM('unused','used','expired') NOT NULL DEFAULT 'unused'");
        }

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropForeign(['import_batch_id']);
            $table->dropForeign(['assigned_to']);
            $table->dropIndex(['order_id']);
            $table->dropIndex(['status']);
            $table->dropColumn(['import_batch_id', 'order_id', 'assigned_to', 'assigned_at', 'amount', 'duration_days']);
        });
    }
};
