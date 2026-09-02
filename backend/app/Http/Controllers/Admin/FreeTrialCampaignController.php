<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FreeTrialCampaign;
use App\Models\RouterPackageProfile;
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

    public function update(Request $request, FreeTrialCampaign $campaign)
    {
        $campaign->update($this->validated($request));
        return $campaign->fresh()->load(['package', 'router']);
    }

    public function destroy(FreeTrialCampaign $campaign)
    {
        $campaign->update(['is_active' => false]);
        return response()->json(['message' => 'Campaign disabled.']);
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
