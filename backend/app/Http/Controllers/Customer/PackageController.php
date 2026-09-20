<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\InternetPackage;
use App\Models\SystemSetting;
use App\Services\CapacityService;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    /**
     * Package listing for the unified Buy Internet page. Returns every
     * active package with its mapped routers.
     *
     * `sales_channel` is included but is now dead weight, not a gate —
     * payment method is a single global toggle (see SystemSetting
     * 'active_payment_method'), not a per-package choice, so there's
     * nothing left for this field to control. Kept in the schema/response
     * rather than ripped out immediately; see docs/PROJECT_OVERVIEW.md.
     *
     * `available_routers` includes EVERY router mapped to this package,
     * live or manual — the router no longer determines whether a
     * purchase is even allowed, only how it gets fulfilled once paid
     * (live → auto-provisioned username/password, manual → voucher from
     * inventory, decided in PurchaseService::fulfill() at confirmation
     * time). This used to filter to live-only routers, back when
     * Paystack was assumed to require live provisioning — that
     * assumption no longer holds.
     */
    public function index(?Request $request = null, ?CapacityService $capacity = null)
    {
        $request ??= request();
        $capacity ??= app(CapacityService::class);
        $customer = $request->user();
        $routerId = $customer?->home_router_id;

        if (! $routerId) {
            return response()->json([
                'packages' => [],
                'assigned_router' => null,
                'message' => 'No router is assigned to your account. Please contact the administrator.',
                'payment_methods' => [
                    'paystack' => $this->gatewayEnabled('paystack'),
                    'momo' => $this->gatewayEnabled('momo'),
                ],
            ]);
        }

        $packages = InternetPackage::active()
            ->whereHas('routerProfiles', fn ($profiles) => $profiles->where('router_id', $routerId))
            ->with(['routerProfiles' => fn ($profiles) => $profiles->where('router_id', $routerId), 'routerProfiles.router' => function ($query) {
                $query->select('id', 'name', 'location', 'status', 'connection_mode', 'momo_enabled', 'paystack_enabled');
            }])
            ->orderBy('price')
            ->get(['id', 'name', 'description', 'price', 'duration_value', 'duration_unit', 'momo_bonus_value', 'momo_bonus_unit', 'speed_limit', 'data_limit', 'sales_channel', 'usage_policy', 'data_allowance_bytes'])
            ->map(function ($package) use ($capacity, $request) {
                return [
                    'id' => $package->id,
                    'name' => $package->name,
                    'description' => $package->description,
                    'price' => $package->price,
                    'duration_value' => $package->duration_value,
                    'duration_unit' => $package->duration_unit,
                    'momo_bonus_value' => $package->momo_bonus_value,
                    'momo_bonus_unit' => $package->momo_bonus_unit,
                    'speed_limit' => $package->speed_limit,
                    'data_limit' => $package->data_limit,
                    'available_routers' => $package->routerProfiles->pluck('router')->filter()->map(function ($router) use ($capacity, $package, $request) {
                        $availability = $capacity->availability($router, $package, $request->user()?->id);

                        return [
                            'id' => $router->id,
                            'name' => $router->name,
                            'location' => $router->location,
                            'status' => $router->status,
                            'connection_mode' => $router->connection_mode,
                            'payment_methods' => [
                                'momo' => $this->gatewayEnabled('momo') && $router->momo_enabled,
                                'paystack' => $this->gatewayEnabled('paystack') && $router->paystack_enabled,
                            ],
                            'capacity_available' => $availability['available'],
                            'capacity_message' => $availability['available']
                                ? 'Capacity available'
                                : $capacity->unavailableMessage($availability['reason']),
                        ];
                    })->values(),
                ];
            });

        return response()->json([
            'packages' => $packages,
            'assigned_router' => $customer->homeRouter()->first(['routers.id', 'routers.name', 'routers.location']),
            'payment_methods' => [
                'paystack' => $this->gatewayEnabled('paystack'),
                'momo' => $this->gatewayEnabled('momo'),
            ],
        ]);
    }

    private function gatewayEnabled(string $gateway): bool
    {
        return filter_var(SystemSetting::get("{$gateway}_enabled", '1'), FILTER_VALIDATE_BOOLEAN);
    }
}
