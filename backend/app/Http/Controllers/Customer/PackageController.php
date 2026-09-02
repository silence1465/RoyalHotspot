<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\InternetPackage;
use App\Models\SystemSetting;

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
    public function index()
    {
        $packages = InternetPackage::active()
                ->with(['routerProfiles.router' => function ($query) {
                    $query->select('id', 'name', 'location', 'status', 'connection_mode');
                }])
                ->orderBy('price')
                ->get(['id', 'name', 'description', 'price', 'duration_value', 'duration_unit', 'momo_bonus_value', 'momo_bonus_unit', 'speed_limit', 'data_limit', 'sales_channel'])
                ->map(function ($package) {
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
                        'available_routers' => $package->routerProfiles->pluck('router')->filter()->values(),
                    ];
                });

        return response()->json([
            'packages' => $packages,
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
