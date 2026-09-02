<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VoucherPdfUploadRequest;
use App\Models\ActivityLog;
use App\Models\Purchase;
use App\Models\Voucher;
use App\Models\VoucherImportBatch;
use App\Services\PurchaseService;
use App\Services\VoucherPdfExtractionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class VoucherImportController extends Controller
{
    public function index(Request $request)
    {
        $query = VoucherImportBatch::with('uploadedBy:id,name');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json(
            $query->latest()->paginate($request->integer('per_page', 10))
        );
    }

    public function show(VoucherImportBatch $batch)
    {
        return response()->json($batch->load('uploadedBy:id,name'));
    }

    /**
     * Upload + extract + parse, in one step. Does NOT create any Voucher
     * rows — the result is a preview stored on the batch
     * (status=pending_review) for the admin to review and confirm
     * separately (see confirm()). This is the mandatory preview step the
     * spec insists on.
     */
    public function upload(VoucherPdfUploadRequest $request, VoucherPdfExtractionService $extractor)
    {
        $file = $request->file('pdf');

        // Stored on the private 'local' disk (see config/filesystems.php)
        // — never web-accessible. Filename randomized to avoid collisions
        // and to not leak the original filename into a public path even
        // if disk config ever changes.
        $storedPath = $file->store('voucher-pdfs', 'local');
        $absolutePath = Storage::disk('local')->path($storedPath);

        $text = $extractor->extractText($absolutePath);
        $parsed = $extractor->parse($text);
        $preview = $extractor->buildPreview($parsed);

        $batch = VoucherImportBatch::create([
            'file_path' => $storedPath,
            'original_filename' => $file->getClientOriginalName(),
            'uploaded_by' => $request->user()->id,
            'router_id' => $request->input('router_id'),
            'status' => 'pending_review',
            'total_extracted' => $preview['totals']['extracted'],
            'extraction_meta' => $preview,
        ]);

        ActivityLog::record(
            'voucher.pdf_uploaded',
            "Uploaded voucher PDF '{$file->getClientOriginalName()}' — {$preview['totals']['extracted']} codes extracted.",
            ['user_id' => $request->user()->id]
        );

        return response()->json($batch, 201);
    }

    /**
     * Finalize an import: turns the batch's (optionally admin-corrected)
     * preview into real Voucher rows. Duplicates are re-checked live
     * against the database at this point — not just relying on the
     * preview snapshot — since another import could have added the same
     * codes between upload() and confirm().
     *
     * Accepts optional overrides in the request body:
     *   - package_overrides: { "<section_index>": <package_id> } — resolve
     *     a section the parser couldn't match to a package by price alone.
     *   - excluded_codes: ["CODE1", "CODE2"] — codes the admin wants to
     *     skip even though they parsed as valid (e.g. visibly mis-OCR'd).
     */
    public function confirm(Request $request, VoucherImportBatch $batch)
    {
        $request->validate([
            'package_overrides' => ['nullable', 'array'],
            'package_overrides.*' => ['integer', 'exists:internet_packages,id'],
            'excluded_codes' => ['nullable', 'array'],
            'excluded_codes.*' => ['string'],
        ]);

        $packageOverrides = $request->input('package_overrides', []);
        $excludedCodes = array_flip(array_map('strtoupper', $request->input('excluded_codes', [])));

        $response = DB::transaction(function () use ($request, $batch, $packageOverrides, $excludedCodes) {
            $locked = VoucherImportBatch::whereKey($batch->id)->lockForUpdate()->first();

            if ($locked->status !== 'pending_review') {
                return [null, response()->json([
                    'message' => "This batch is already '{$locked->status}' and can't be imported again.",
                ], 422)];
            }

            $meta = $locked->extraction_meta;
            $allCandidateCodes = [];

            foreach ($meta['sections'] as $section) {
                foreach ($section['codes'] as $entry) {
                    $allCandidateCodes[] = $entry['code'];
                }
            }

            // Re-check duplicates live — the preview snapshot could be
            // stale if time has passed since upload().
            $existingCodes = Voucher::whereIn('code', $allCandidateCodes)->pluck('code')->flip();

            $imported = 0;
            $duplicates = 0;
            $invalid = 0;
            $seenThisRun = [];
            $rows = [];
            $touchedPackageIds = [];
            $now = now();

            foreach ($meta['sections'] as $index => $section) {
                $packageId = $packageOverrides[$index] ?? $section['matched_package_id'];

                if (! $packageId) {
                    $invalid += count($section['codes']);
                    continue; // admin needs to resolve this section's package before it can import
                }

                foreach ($section['codes'] as $entry) {
                    $code = $entry['code'];

                    if (isset($excludedCodes[$code])) {
                        continue; // admin explicitly excluded — not counted as invalid or duplicate
                    }

                    if (isset($seenThisRun[$code]) || isset($existingCodes[$code])) {
                        $duplicates++;
                        continue;
                    }

                    $seenThisRun[$code] = true;
                    $touchedPackageIds[$packageId] = true;

                    $rows[] = [
                        'code' => $code,
                        'package_id' => $packageId,
                        'router_id' => $locked->router_id, // see migration 2024_02_01_000010
                        'status' => 'available',
                        'import_batch_id' => $locked->id,
                        'amount' => $section['amount'],
                        'duration_days' => $section['duration_days'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $imported++;
                }
            }

            // unassigned_codes / unparsed_lines were already counted into
            // total_invalid at preview time (see buildPreview) — nothing
            // importable there since they have no package association.
            $invalid += count($meta['unassigned_codes']) + count($meta['unparsed_lines']);

            if (! empty($rows)) {
                // chunked insert — a 250+ row PDF is small, but this keeps
                // a future much-larger import from building one giant query
                foreach (array_chunk($rows, 200) as $chunk) {
                    Voucher::insert($chunk);
                }
            }

            $locked->update([
                'status' => 'imported',
                'total_imported' => $imported,
                'total_duplicates' => $duplicates,
                'total_invalid' => $invalid,
                'imported_at' => $now,
            ]);

            ActivityLog::record(
                'voucher.pdf_imported',
                "Imported {$imported} voucher(s) from batch #{$locked->id} ({$duplicates} duplicates skipped, {$invalid} invalid).",
                ['user_id' => $request->user()->id]
            );

            return [array_keys($touchedPackageIds), response()->json([
                'message' => 'Import complete.',
                'batch' => $locked->fresh(),
            ])];
        });

        [$touchedPackageIds, $httpResponse] = $response;

        // Backfill: a purchase can be sitting 'verified' with no voucher
        // because inventory ran out at payment time (see PurchaseService).
        // A fresh import for that same package is exactly the moment to
        // retry those purchases. Deliberately OUTSIDE the import
        // transaction above.
        if ($touchedPackageIds) {
            $purchaseService = app(PurchaseService::class);

            Purchase::where('status', 'verified')
                ->whereNull('voucher_id')
                ->where('fulfillment_type', 'voucher')
                ->whereIn('package_id', $touchedPackageIds)
                ->oldest('verified_at')
                ->get()
                ->each(fn (Purchase $purchase) => $purchaseService->fulfill($purchase));
        }

        return $httpResponse;
    }

    public function cancel(Request $request, VoucherImportBatch $batch)
    {
        if ($batch->status !== 'pending_review') {
            return response()->json([
                'message' => "This batch is already '{$batch->status}' and can't be cancelled.",
            ], 422);
        }

        $batch->update(['status' => 'cancelled']);

        ActivityLog::record(
            'voucher.pdf_import_cancelled',
            "Cancelled voucher PDF import batch #{$batch->id}.",
            ['user_id' => $request->user()->id]
        );

        return response()->json($batch->fresh());
    }
}
