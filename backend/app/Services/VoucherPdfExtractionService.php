<?php

namespace App\Services;

use App\Models\InternetPackage;
use App\Models\Voucher;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfTextParser;

/**
 * Turns an uploaded voucher PDF into a structured preview: per-package
 * sections, each with a list of codes tagged valid/duplicate/malformed,
 * plus any lines that didn't match anything (surfaced to the admin
 * rather than silently dropped).
 *
 * Deliberately does NOT write to the database — this is a pure parser.
 * VoucherImportController is what turns a parse result into actual
 * Voucher rows, and only after the admin confirms the preview.
 */
class VoucherPdfExtractionService
{
    /**
     * Extract raw text from a PDF file on disk. Tries normal text
     * extraction first (fast, works for any digitally-generated PDF,
     * which a MikroTik voucher export almost certainly is). Falls back
     * to OCR only if the result looks suspiciously empty AND OCR is
     * both enabled in config and actually available on this server.
     */
    public function extractText(string $absolutePath): string
    {
        $text = $this->extractTextNatively($absolutePath);

        if (mb_strlen(trim($text)) >= config('voucherimport.ocr_trigger_min_chars')) {
            return $text;
        }

        if (! config('voucherimport.ocr_enabled')) {
            return $text; // possibly sparse/empty — caller surfaces this to the admin
        }

        $ocrText = $this->extractTextViaOcr($absolutePath);

        // Prefer whichever extraction found more — OCR isn't guaranteed
        // to be better, just attempted when normal extraction looks thin.
        return mb_strlen($ocrText) > mb_strlen($text) ? $ocrText : $text;
    }

    protected function extractTextNatively(string $absolutePath): string
    {
        try {
            $pdf = (new PdfTextParser())->parseFile($absolutePath);
            return $pdf->getText() ?? '';
        } catch (\Throwable $e) {
            Log::warning('Voucher PDF text extraction failed', ['error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Best-effort OCR via CLI tools (poppler-utils' pdftoppm + tesseract),
     * not a PHP extension dependency — checks both binaries are actually
     * present before attempting anything, and never throws; a failure
     * here just means the admin sees a thinner extraction and can
     * correct codes manually in the preview.
     */
    protected function extractTextViaOcr(string $absolutePath): string
    {
        if (! $this->binaryExists('pdftoppm') || ! $this->binaryExists('tesseract')) {
            Log::info('Voucher PDF OCR skipped — pdftoppm or tesseract not installed on this server.');
            return '';
        }

        $tempDir = sys_get_temp_dir() . '/voucher-ocr-' . uniqid();
        mkdir($tempDir, 0700, true);
        $outputPrefix = $tempDir . '/page';
        $maxPages = (int) config('voucherimport.ocr_max_pages');

        try {
            $escapedPath = escapeshellarg($absolutePath);
            $escapedPrefix = escapeshellarg($outputPrefix);
            shell_exec("pdftoppm -png -r 200 -l {$maxPages} {$escapedPath} {$escapedPrefix} 2>&1");

            $images = glob($outputPrefix . '*.png');
            sort($images);

            $text = '';
            foreach ($images as $image) {
                $escapedImage = escapeshellarg($image);
                $text .= shell_exec("tesseract {$escapedImage} stdout 2>/dev/null") . "\n";
            }

            return $text;
        } catch (\Throwable $e) {
            Log::warning('Voucher PDF OCR failed', ['error' => $e->getMessage()]);
            return '';
        } finally {
            array_map('unlink', glob($tempDir . '/*') ?: []);
            @rmdir($tempDir);
        }
    }

    protected function binaryExists(string $binary): bool
    {
        $path = trim((string) shell_exec('which ' . escapeshellarg($binary) . ' 2>/dev/null'));
        return $path !== '';
    }

    /**
     * Parse raw extracted text into package sections + codes.
     *
     * Returns:
     * [
     *   'sections' => [
     *     [
     *       'raw_header' => 'GH₵30 - 1 Week',
     *       'amount' => 30.0,
     *       'duration_days' => 7,
     *       'matched_package_id' => 3|null,
     *       'codes' => ['RW30-82KD-91PL', ...],
     *     ],
     *   ],
     *   'unassigned_codes' => [...],   // codes found before any package header
     *   'unparsed_lines' => [...],     // lines matching neither pattern
     * ]
     */
    public function parse(string $text): array
    {
        $codePattern = config('voucherimport.code_pattern');
        $headerPattern = config('voucherimport.package_header_pattern');

        $lines = preg_split('/\r\n|\r|\n/', $text);

        $sections = [];
        $currentIndex = null;
        $unassignedCodes = [];
        $unparsedLines = [];

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                continue;
            }

            if (preg_match($headerPattern, $line, $m)) {
                $amount = (float) str_replace(',', '', $m[1]);
                $durationNumber = (int) $m[2];
                $unit = strtolower($m[3]);
                $durationDays = $this->normalizeDurationToDays($durationNumber, $unit);

                $sections[] = [
                    'raw_header' => $line,
                    'amount' => $amount,
                    'duration_days' => $durationDays,
                    'matched_package_id' => $this->matchPackage($amount, $durationDays),
                    'codes' => [],
                ];
                $currentIndex = array_key_last($sections);

                continue;
            }

            if (preg_match($codePattern, $line)) {
                $code = strtoupper($line);

                if ($currentIndex !== null) {
                    $sections[$currentIndex]['codes'][] = $code;
                } else {
                    $unassignedCodes[] = $code;
                }

                continue;
            }

            // Neither a recognized header nor a recognized code — could be
            // a title line ("Royal WiFi Vouchers"), page number, or a
            // malformed code the admin needs to look at. Surfaced, not
            // discarded.
            $unparsedLines[] = $line;
        }

        return [
            'sections' => $sections,
            'unassigned_codes' => $unassignedCodes,
            'unparsed_lines' => $unparsedLines,
        ];
    }

    protected function normalizeDurationToDays(int $number, string $unit): int
    {
        return match (true) {
            str_starts_with($unit, 'day') => $number,
            str_starts_with($unit, 'week') => $number * 7,
            str_starts_with($unit, 'month') => $number * 30,
            default => $number,
        };
    }

    /**
     * Match a detected package header to an actual InternetPackage row by
     * price (the most reliable signal from a PDF). Null if no package at
     * that exact price exists yet — the admin resolves this manually in
     * the preview (see VoucherImportController::confirm's package_overrides)
     * rather than the importer guessing.
     */
    protected function matchPackage(float $amount, int $durationDays): ?int
    {
        return InternetPackage::where('price', $amount)->value('id');
    }

    /**
     * Given a parsed structure, build the admin-facing preview: per-code
     * status (valid / duplicate_in_batch / duplicate_existing / malformed)
     * and per-section totals. This is what gets stored in
     * voucher_import_batches.extraction_meta and shown to the admin
     * before any Voucher rows are created.
     */
    public function buildPreview(array $parsed): array
    {
        $existingCodes = Voucher::whereIn('code', $this->allCodes($parsed))
            ->pluck('code')
            ->flip(); // O(1) lookup

        $seenInBatch = [];
        $totalValid = 0;
        $totalDuplicate = 0;
        $totalInvalid = 0;

        $sections = array_map(function ($section) use ($existingCodes, &$seenInBatch, &$totalValid, &$totalDuplicate, &$totalInvalid) {
            $codes = array_map(function ($code) use ($existingCodes, &$seenInBatch, &$totalValid, &$totalDuplicate, &$totalInvalid) {
                if (isset($seenInBatch[$code])) {
                    $status = 'duplicate_in_batch';
                } elseif (isset($existingCodes[$code])) {
                    $status = 'duplicate_existing';
                } else {
                    $status = 'valid';
                }

                $seenInBatch[$code] = true;

                match ($status) {
                    'valid' => $totalValid++,
                    default => $totalDuplicate++,
                };

                return ['code' => $code, 'status' => $status];
            }, $section['codes']);

            if ($section['matched_package_id'] === null) {
                $totalInvalid += count($codes);
            }

            $section['codes'] = $codes;
            return $section;
        }, $parsed['sections']);

        $totalInvalid += count($parsed['unassigned_codes']) + count($parsed['unparsed_lines']);

        return [
            'sections' => $sections,
            'unassigned_codes' => $parsed['unassigned_codes'],
            'unparsed_lines' => $parsed['unparsed_lines'],
            'totals' => [
                'extracted' => $totalValid + $totalDuplicate + $totalInvalid,
                'valid' => $totalValid,
                'duplicate' => $totalDuplicate,
                'invalid' => $totalInvalid,
            ],
        ];
    }

    protected function allCodes(array $parsed): array
    {
        $codes = $parsed['unassigned_codes'];

        foreach ($parsed['sections'] as $section) {
            $codes = array_merge($codes, $section['codes']);
        }

        return $codes;
    }
}
