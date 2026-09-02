<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Genuine gap found while wiring up MikroTik status-checking (Extension
 * Phase 5): a PDF-imported voucher never captured WHICH router it was
 * generated on, making it structurally impossible to know which MikroTik
 * device to query for that code's live status. An admin generating
 * vouchers on MikroTik does so per-router (each router = one physical
 * hotspot location), and a single PDF export is almost always from one
 * specific router's batch — so this is captured once per import batch,
 * not per voucher, and propagated to every voucher row the batch produces.
 *
 * Nullable and optional: a batch (and the vouchers from it) can still be
 * imported without selecting a router, same as before this migration —
 * only the new MikroTik-status-sync feature requires it. Nothing about
 * the existing import/confirm flow becomes mandatory-router as a result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_import_batches', function (Blueprint $table) {
            $table->foreignId('router_id')->nullable()->after('uploaded_by')
                ->constrained('routers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('voucher_import_batches', function (Blueprint $table) {
            $table->dropForeign(['router_id']);
            $table->dropColumn('router_id');
        });
    }
};
