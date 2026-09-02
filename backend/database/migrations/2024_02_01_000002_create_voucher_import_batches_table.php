<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A PDF upload gets its own row here (not just a raw UUID like the
 * existing `vouchers.batch_id` used for in-app-generated batches) because
 * the spec explicitly wants: file storage, extraction preview data,
 * import history, and "View import batch" from the admin UI. A bare UUID
 * has nowhere to hang that metadata.
 *
 * `vouchers.batch_id` (existing, UUID) stays exactly as-is for the
 * original generate-in-app flow. `vouchers.import_batch_id` (new, FK to
 * this table) is used only for PDF-imported vouchers. A voucher is never
 * both — see docs/DATABASE_SCHEMA.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('file_path'); // stored original PDF, see storage note in docs
            $table->string('original_filename');
            $table->foreignId('uploaded_by')->constrained('users');

            // pending_review: extracted, shown in preview, not yet imported.
            // imported: admin confirmed, vouchers rows created.
            // cancelled: admin discarded the preview without importing.
            $table->enum('status', ['pending_review', 'imported', 'cancelled'])->default('pending_review');

            $table->unsignedInteger('total_extracted')->default(0);
            $table->unsignedInteger('total_imported')->default(0);
            $table->unsignedInteger('total_duplicates')->default(0);
            $table->unsignedInteger('total_invalid')->default(0);

            // Raw parser output (per-package breakdown, per-line extraction
            // detail) — lets the preview screen re-render without
            // re-parsing the PDF, and gives admins something to inspect if
            // extraction looks wrong.
            $table->json('extraction_meta')->nullable();

            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_import_batches');
    }
};
