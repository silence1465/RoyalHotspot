<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Voucher;

/**
 * Royal WiFi vouchers are codes generated directly on MikroTik, outside
 * this app — the app only ever learns about them via PDF import and only
 * ever hands them out. This service is how the app can OPTIONALLY look
 * back at the router to see what actually happened to a code after that:
 * has it been used to log into the hotspot at all?
 *
 * Deliberately reuses the EXISTING MikrotikService (see app/Services/
 * MikrotikService.php, built in the original project) rather than
 * opening any new kind of MikroTik connection — this is business logic
 * layered on top of the same RouterOS calls the rest of the app already
 * makes, not a second integration.
 */
class VoucherUsageSyncService
{
    /**
     * Check one voucher's live status. Safe to call repeatedly — only
     * writes to the voucher row if RouterOS shows genuine usage that the
     * database doesn't already reflect.
     */
    public function checkVoucher(Voucher $voucher): array
    {
        if (! $voucher->router_id) {
            return [
                'success' => false,
                'message' => 'This voucher has no associated router — re-import its batch with a router selected to enable status checks (see docs/DATABASE_SCHEMA.md).',
            ];
        }

        $mikrotik = new MikrotikService($voucher->router);
        $result = $mikrotik->getHotspotUsers();

        if (! $result['success']) {
            return [
                'success' => false,
                'message' => 'Could not reach the router: ' . ($result['error'] ?? 'unknown error'),
            ];
        }

        $hotspotUser = collect($result['data'] ?? [])
            ->first(fn ($u) => isset($u['name']) && strcasecmp($u['name'], $voucher->code) === 0);

        if (! $hotspotUser) {
            return [
                'success' => false,
                'message' => 'No matching hotspot user found on this router for this code. It may use a different username than the voucher code, or may not exist on this router at all.',
            ];
        }

        $uptimeSeconds = $this->parseRouterOsUptime($hotspotUser['uptime'] ?? '0s');
        $bytesIn = (int) ($hotspotUser['bytes-in'] ?? 0);
        $hasBeenUsed = $uptimeSeconds > 0 || $bytesIn > 0;

        if ($hasBeenUsed && $voucher->status !== 'used') {
            $voucher->update([
                'status' => 'used',
                // Backfill from whatever we already know — this voucher
                // was handed to assigned_to via a completed order, so
                // that's who actually used it (see the assigned_to vs
                // used_by_customer_id distinction in
                // 2024_02_01_000003's docblock).
                'used_by_customer_id' => $voucher->used_by_customer_id ?? $voucher->assigned_to,
                'used_at' => $voucher->used_at ?? now(),
            ]);

            ActivityLog::record(
                'voucher.usage_confirmed_mikrotik',
                "Voucher {$voucher->code} confirmed used on MikroTik (synced from router {$voucher->router->name}).",
                $voucher->assigned_to ? ['customer_id' => $voucher->assigned_to] : []
            );
        }

        return [
            'success' => true,
            'mikrotik_disabled' => ($hotspotUser['disabled'] ?? 'false') === 'true',
            'uptime_seconds' => $uptimeSeconds,
            'bytes_in' => $bytesIn,
            'has_been_used' => $hasBeenUsed,
            'voucher_status' => $voucher->fresh()->status,
        ];
    }

    /**
     * RouterOS reports uptime as a compact string like "1d2h3m4s" or
     * "45m30s" or "0s" — parses it into total seconds.
     */
    protected function parseRouterOsUptime(string $uptime): int
    {
        preg_match_all('/(\d+)([wdhms])/', $uptime, $matches, PREG_SET_ORDER);

        $multipliers = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        $seconds = 0;

        foreach ($matches as $match) {
            $seconds += ((int) $match[1]) * ($multipliers[$match[2]] ?? 0);
        }

        return $seconds;
    }
}
