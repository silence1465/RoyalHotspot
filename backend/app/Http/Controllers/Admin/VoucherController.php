<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VoucherGenerateRequest;
use App\Models\ActivityLog;
use App\Models\InternetPackage;
use App\Models\Router;
use App\Models\SystemSetting;
use App\Models\Voucher;
use App\Services\MikrotikService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VoucherController extends Controller
{
    /**
     * Per-package inventory breakdown for the admin Vouchers page —
     * available/reserved/assigned/used/expired/invalid counts plus a
     * low-stock flag. Threshold is a real editable setting
     * (SystemSetting), not hardcoded, per the spec's own instruction on
     * package prices/durations — same principle applies here.
     */
    public function inventory()
    {
        $counts = Voucher::selectRaw('package_id, status, count(*) as count')
            ->groupBy('package_id', 'status')
            ->get()
            ->groupBy('package_id');

        $threshold = (int) (SystemSetting::get('low_stock_threshold') ?? 10);

        $packages = InternetPackage::active()->get(['id', 'name']);

        $inventory = $packages->map(function ($package) use ($counts, $threshold) {
            $byStatus = ($counts->get($package->id) ?? collect())->pluck('count', 'status');
            $available = (int) ($byStatus['available'] ?? 0);

            return [
                'package_id' => $package->id,
                'package_name' => $package->name,
                'available' => $available,
                'reserved' => (int) ($byStatus['reserved'] ?? 0),
                'assigned' => (int) ($byStatus['assigned'] ?? 0),
                'used' => (int) ($byStatus['used'] ?? 0),
                'expired' => (int) ($byStatus['expired'] ?? 0),
                'invalid' => (int) ($byStatus['invalid'] ?? 0),
                'low_stock' => $available <= $threshold,
            ];
        });

        return response()->json([
            'threshold' => $threshold,
            'packages' => $inventory,
        ]);
    }

    public function index(Request $request)
    {
        $query = Voucher::with(['package:id,name', 'router:id,name', 'usedBy:id,full_name,username']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($batchId = $request->query('batch_id')) {
            $query->where('batch_id', $batchId);
        }

        if ($packageId = $request->query('package_id')) {
            $query->where('package_id', $packageId);
        }

        return response()->json(
            $query->latest()->paginate($request->integer('per_page', 10))
        );
    }

    /**
     * Generates codes AND actually creates the corresponding hotspot
     * user on the router for each one — this used to only create
     * database rows with no real MikroTik-side login behind them,
     * meaning a customer could be handed a code that simply didn't
     * work on the actual hotspot. Found via a real support report, not
     * caught during the original build. Manual routers are blocked
     * outright here: PDF Import is the correct path for a router with
     * no live connection, since those codes must already exist on the
     * router before the app ever hears about them — this endpoint's
     * whole premise (create it live, right now) is impossible there.
     */
    public function generate(VoucherGenerateRequest $request)
    {
        $data = $request->validated();
        $router = Router::findOrFail($data['router_id']);

        if ($router->isManual()) {
            return response()->json([
                'message' => 'This router is set to Manual mode — there\'s no live connection to create codes on. Use Vouchers → Import PDF instead for codes you\'ve already generated directly on the router.',
            ], 422);
        }

        $package = InternetPackage::findOrFail($data['package_id']);
        $profileName = $router->profileNameFor($package);

        if (! $profileName) {
            return response()->json([
                'message' => 'This package has no RouterOS profile mapped for this router — set that up under Packages first.',
            ], 422);
        }

        $mikrotik = new MikrotikService($router);

        // Belt-and-suspenders: package saves already push this profile to
        // every mapped router (Admin\PackageController::pushProfilesToRouters),
        // but a router that was offline at that point would only have the
        // DB mapping, not the real RouterOS profile — creating a hotspot
        // user against a profile that doesn't exist on the router fails.
        // Ensuring it here means voucher generation can't be blocked by a
        // sync that happened to miss this router earlier.
        $profileResult = $mikrotik->ensureHotspotUserProfile($profileName, $package->speed_limit, $router->address_pool);

        if (! $profileResult['success']) {
            return response()->json([
                'message' => "Couldn't prepare the RouterOS profile '{$profileName}' on this router: {$profileResult['error']}",
            ], 502);
        }

        $batchId = (string) Str::uuid();

        $created = [];
        $failed = 0;

        for ($i = 0; $i < $data['quantity']; $i++) {
            $code = Voucher::generateCode();

            // Same code used as both username and password — matches
            // the single-field convention every other voucher/guest
            // code in this system already uses.
            $result = $mikrotik->createHotspotUser($code, $code, $profileName);

            if (! $result['success']) {
                $failed++;
                continue; // don't create a DB row for a code that was never actually created on the router
            }

            $created[] = Voucher::create([
                'code' => $code,
                'package_id' => $data['package_id'],
                'router_id' => $router->id,
                'mikrotik_user_id' => $result['data']['.id'] ?? null,
                'status' => 'available',
                'expires_at' => $data['expires_at'] ?? null,
                'batch_id' => $batchId,
                'generated_by' => $request->user()->id,
            ]);
        }

        ActivityLog::record(
            'voucher.generated',
            'Generated ' . count($created) . " voucher(s) live on '{$router->name}' for package #{$data['package_id']} (batch {$batchId})"
                . ($failed > 0 ? ", {$failed} failed" : '') . '.',
            ['user_id' => $request->user()->id]
        );

        return response()->json([
            'batch_id' => $batchId,
            'count' => count($created),
            'failed' => $failed,
            'vouchers' => $created,
        ], $failed > 0 && count($created) === 0 ? 502 : 201);
    }

    public function destroy(Request $request, Voucher $voucher)
    {
        // Deleting a used voucher would erase the audit trail linking a
        // customer's activation back to how they paid for it — only
        // unused or already-expired vouchers can be removed.
        if ($voucher->status === 'used') {
            return response()->json([
                'message' => 'Cannot delete a voucher that has already been redeemed.',
            ], 422);
        }

        $voucher->delete();

        ActivityLog::record(
            'voucher.deleted',
            "Voucher {$voucher->code} deleted.",
            ['user_id' => $request->user()->id]
        );

        return response()->json(['message' => 'Voucher deleted.']);
    }

    /**
     * On-demand single-voucher MikroTik status check (Extension Phase 5)
     * — works regardless of `voucherimport.mikrotik_sync_enabled`, since
     * this is exactly the safe way to validate the router/code-matching
     * assumption against one real voucher before ever enabling bulk sync.
     */
    public function checkMikrotikStatus(Voucher $voucher, \App\Services\VoucherUsageSyncService $syncService)
    {
        $result = $syncService->checkVoucher($voucher);

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
