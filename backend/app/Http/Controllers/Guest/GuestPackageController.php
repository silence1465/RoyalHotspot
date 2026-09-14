<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

class GuestPackageController extends Controller
{
    /**
     * Guest checkout is scoped to exactly one router — the admin pastes a
     * distinct link per physical hotspot, so there's no location picker
     * the way the registered customer flow has. Guest checkout is also
     * live-router-only: a manual router has no way to generate a fresh
     * code on the spot, which is the whole guest fulfillment mechanism.
     */
    public function index(Request $request)
    {
        $router = Router::find($request->query('router_id'));

        if (! $router) {
            return response()->json(['message' => 'Unknown location.'], 404);
        }

        if ($router->isManual()) {
            return response()->json([
                'message' => 'Guest purchase isn\'t available at this location right now.',
                'router' => ['id' => $router->id, 'name' => $router->name],
                'packages' => [],
            ], 200);
        }

        $packages = $router->packageProfiles()
            ->with('package')
            ->get()
            ->pluck('package')
            ->filter(fn ($p) => $p && $p->status === 'active' && $p->available_to_guests)
            ->values()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'price' => $p->price,
                'duration_value' => $p->duration_value,
                'duration_unit' => $p->duration_unit,
                'speed_limit' => $p->speed_limit,
                'data_limit' => $p->data_limit,
            ]);

        return response()->json([
            'router' => ['id' => $router->id, 'name' => $router->name],
            'packages' => $packages,
            'payment_methods' => [
                'momo' => $router->momo_enabled
                    && filter_var(SystemSetting::get('momo_enabled', '1'), FILTER_VALIDATE_BOOLEAN),
            ],
        ]);
    }
}
