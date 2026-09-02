<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    */

    'max_file_size_kb' => env('VOUCHER_PDF_MAX_KB', 10240), // 10MB default

    /*
    |--------------------------------------------------------------------------
    | Voucher code pattern
    |--------------------------------------------------------------------------
    |
    | Matches codes like "RW30-82KD-91PL". Adjust this if your actual
    | MikroTik-generated PDF format differs — this is the single place to
    | change it. Requires at least 2 dash-separated segments of 2+
    | alphanumeric characters each, to avoid false-matching ordinary words
    | in the PDF (like a package header) as a voucher code.
    |
    */
    'code_pattern' => '/^[A-Z0-9]{2,}(-[A-Z0-9]{2,}){1,4}$/i',

    /*
    |--------------------------------------------------------------------------
    | Package header pattern
    |--------------------------------------------------------------------------
    |
    | Matches lines like "GH₵30 - 1 Week" or "GHS 100 - 1 Month" that
    | introduce a block of voucher codes for that package. Captures the
    | amount and the duration number/unit separately.
    |
    */
    'package_header_pattern' => '/GH[₵C¢]?S?\s*([\d,]+(?:\.\d{1,2})?)\s*[-–—\/]\s*(\d+)\s*(day|days|week|weeks|month|months)/iu',

    /*
    |--------------------------------------------------------------------------
    | OCR fallback (scanned/image-based PDFs)
    |--------------------------------------------------------------------------
    |
    | Off by default — enable only once `pdftoppm` (poppler-utils) and
    | `tesseract` are actually installed on the server (see
    | docs/DEPLOYMENT_GUIDE.md). Even when enabled, VoucherPdfExtractionService
    | checks both binaries are actually present at runtime before
    | attempting OCR, and degrades gracefully (returns whatever normal
    | text extraction found, flags ocr_available=false in the preview) if
    | they're missing — never a hard failure.
    |
    */
    'ocr_enabled' => env('VOUCHER_PDF_OCR_ENABLED', false),

    // If normal text extraction yields fewer characters than this, the
    // PDF is assumed to be scanned/image-based and OCR is attempted (if
    // enabled and available).
    'ocr_trigger_min_chars' => 40,

    // Only OCR the first N pages — a 250-voucher PDF might be many pages;
    // OCR is slow, and an admin reviewing the preview will notice quickly
    // if extraction looks incomplete and can re-run with a higher limit.
    'ocr_max_pages' => 20,

    /*
    |--------------------------------------------------------------------------
    | MikroTik status sync (Extension Phase 5)
    |--------------------------------------------------------------------------
    |
    | Off by default. Reuses the EXISTING MikrotikService (see
    | VoucherUsageSyncService) to check whether a PDF-imported voucher's
    | code actually exists as a hotspot user on its router, and whether
    | it's been used (RouterOS session uptime > 0). This assumes the
    | voucher code IS the RouterOS hotspot username — true for a typical
    | MikroTik voucher setup, but confirm it against your actual router
    | before enabling automatic syncing. The manual per-voucher check
    | (Admin\VoucherController::checkMikrotikStatus) works regardless of
    | this setting and is the safer way to validate the assumption first.
    |
    */
    'mikrotik_sync_enabled' => env('VOUCHER_MIKROTIK_SYNC_ENABLED', false),

];
