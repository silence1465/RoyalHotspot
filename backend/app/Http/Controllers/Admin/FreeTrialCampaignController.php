<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\FreeTrialCampaign;
use App\Models\Purchase;
use App\Models\RouterPackageProfile;
use App\Services\PurchaseService;
use Illuminate\Http\Request;

class FreeTrialCampaignController extends Controller
{
    public function index()
    {
        return FreeTrialCampaign::with(['package:id,name,duration_value,duration_unit', 'router:id,name,location,connection_mode'])
            ->withCount('purchases')
            ->latest()->get();
    }

    public function store(Request $request)
    {
        $campaign = FreeTrialCampaign::create($this->validated($request));
        return response()->json($campaign->load(['package', 'router']), 201);
    }

    public function update(Request $request, FreeTrialCampaign $campaign, PurchaseService $purchaseService)
    {
        $data = $this->validated($request);
        $wasActive = $campaign->is_active;
        $campaign->update($data);
        $revoked = $wasActive && array_key_exists('is_active', $data) && ! $data['is_active']
            ? $this->revokeActiveClaims($request, $campaign, $purchaseService)
            : 0;

        return response()->json([
            'campaign' => $campaign->fresh()->load(['package', 'router']),
            'revoked_claims' => $revoked,
        ]);
    }

    public function destroy(Request $request, FreeTrialCampaign $campaign, PurchaseService $purchaseService)
    {
        $campaign->update(['is_active' => false]);
        $revoked = $this->revokeActiveClaims($request, $campaign, $purchaseService);

        return response()->json([
            'message' => "Campaign disabled. {$revoked} active free-trial claim(s) revoked.",
            'revoked_claims' => $revoked,
        ]);
    }

    private function revokeActiveClaims(
        Request $request,
        FreeTrialCampaign $campaign,
        PurchaseService $purchaseService
    ): int {
        $claims = Purchase::where('free_trial_campaign_id', $campaign->id)
            ->active()
            ->with(['customer', 'router', 'voucher'])
            ->get();

        foreach ($claims as $claim) {
            $purchaseService->expirePurchase($claim);
        }

        ActivityLog::record('free_trial.campaign_revoked', json_encode([
            'campaign_id' => $campaign->id,
            'revoked_claims' => $claims->count(),
        ], JSON_UNESCAPED_SLASHES), ['user_id' => $request->user()?->id]);

        return $claims->count();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'package_id' => ['required', 'exists:internet_packages,id'],
            'router_id' => ['required', 'exists:routers,id,connection_mode,live'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $mapped = RouterPackageProfile::where('package_id', $data['package_id'])
            ->where('router_id', $data['router_id'])->exists();
        abort_unless($mapped, 422, 'The selected package must be mapped to the selected router.');

        return $data;
    }
}
