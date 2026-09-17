<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\HotspotSession;
use App\Models\HotspotUser;
use App\Services\MikrotikServiceFactory;
use App\Support\AdminRouterScope;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::query();
        $routerIds = AdminRouterScope::ids($request);
        if ($routerIds !== null) {
            $query->whereHas('purchases', fn ($purchases) => $purchases->whereIn('router_id', $routerIds));
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json(
            $query->latest()->paginate($request->integer('per_page', 10))
        );
    }

    public function show(Request $request, Customer $customer)
    {
        $routerIds = AdminRouterScope::ids($request);
        if ($routerIds !== null && ! $customer->purchases()->whereIn('router_id', $routerIds)->exists()) {
            abort(404);
        }

        $methods = array_values(array_filter(['paystack', 'momo'], fn ($method) => $request->user()->hasPermission("transactions.{$method}.view")));

        return response()->json(
            $customer->load([
                'subscriptions' => fn ($q) => $q->when($routerIds !== null, fn ($s) => $s->whereIn('router_id', $routerIds))->with(['package:id,name', 'router:id,name'])->latest(),
                'payments' => fn ($q) => $q->whereIn('provider', $methods)->when($routerIds !== null, fn ($payments) => $payments->whereHas('purchase', fn ($p) => $p->whereIn('router_id', $routerIds)))->latest()->take(20),
                'hotspotUsers' => fn ($q) => $q->when($routerIds !== null, fn ($users) => $users->whereIn('router_id', $routerIds))->with('router:id,name'),
            ])
        );
    }

    public function resetHotspotPassword(
        Request $request,
        Customer $customer,
        HotspotUser $hotspotUser,
        MikrotikServiceFactory $mikrotikFactory
    ) {
        abort_unless($hotspotUser->customer_id === $customer->id, 404);
        $routerIds = AdminRouterScope::ids($request);
        abort_if($routerIds !== null && ! in_array($hotspotUser->router_id, $routerIds, true), 403);

        $data = $request->validate([
            'password' => ['nullable', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],
        ]);
        $password = $data['password'] ?? Str::password(12, symbols: false);
        $result = $mikrotikFactory->make($hotspotUser->router)
            ->resetHotspotUserPassword($hotspotUser->username, $password);

        if (! $result['success']) {
            return response()->json([
                'message' => 'MikroTik rejected the password reset: '.($result['error'] ?? 'unknown error'),
            ], 502);
        }

        $hotspotUser->update([
            'password' => $password,
            'mikrotik_user_id' => $result['data']['mikrotik_user_id'] ?? $hotspotUser->mikrotik_user_id,
        ]);

        HotspotSession::where('customer_id', $customer->id)
            ->where('router_id', $hotspotUser->router_id)
            ->whereNull('ended_at')
            ->update([
                'status' => 'disconnected',
                'disconnect_reason' => 'wifi_password_reset',
                'ended_at' => now(),
            ]);

        ActivityLog::record(
            'customer.hotspot_password_reset',
            "Wi-Fi password reset for {$hotspotUser->username} on router #{$hotspotUser->router_id}.",
            ['user_id' => $request->user()->id]
        );

        return response()->json([
            'message' => 'Wi-Fi password reset. Existing sessions and saved login cookies were removed.',
            'username' => $hotspotUser->username,
            'temporary_password' => $password,
            'router_id' => $hotspotUser->router_id,
        ]);
    }
}
