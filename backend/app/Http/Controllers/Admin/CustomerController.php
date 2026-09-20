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
use Illuminate\Support\Facades\DB;
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

    public function destroyTestData(Request $request, Customer $customer, MikrotikServiceFactory $mikrotikFactory)
    {
        $request->validate(['confirmation' => ['required', 'in:DELETE TEST DATA']]);

        if ($customer->purchases()->active()->exists()) {
            return response()->json([
                'message' => 'Cancel or expire this customer’s active purchase before deleting test data.',
            ], 422);
        }

        $hotspotUsers = $customer->hotspotUsers()->with('router')->get();
        foreach ($hotspotUsers as $hotspotUser) {
            if (! $hotspotUser->router || $hotspotUser->router->isManual()) {
                continue;
            }
            $mikrotik = $mikrotikFactory->make($hotspotUser->router);
            $disconnect = $mikrotik->disableAndDisconnectHotspotUser($hotspotUser->username, $hotspotUser->mikrotik_user_id);
            if (! $disconnect['success']) {
                return response()->json([
                    'message' => "Could not disconnect {$hotspotUser->username} from {$hotspotUser->router->name}: ".($disconnect['error'] ?? 'unknown error'),
                ], 502);
            }
            if ($hotspotUser->mikrotik_user_id) {
                $removed = $mikrotik->removeHotspotUser($hotspotUser->mikrotik_user_id);
                if (! $removed['success']) {
                    return response()->json([
                        'message' => "The session was disconnected, but the test user could not be removed from {$hotspotUser->router->name}: ".($removed['error'] ?? 'unknown error'),
                    ], 502);
                }
            }
        }

        $summary = [
            'customer_id' => $customer->id,
            'username' => $customer->username,
            'purchases' => $customer->purchases()->count(),
            'payments' => $customer->payments()->count(),
            'hotspot_users' => $hotspotUsers->count(),
        ];

        DB::transaction(function () use ($customer) {
            $customer->update(['current_purchase_id' => null]);
            DB::table('vouchers')->where('used_by_customer_id', $customer->id)->update(['used_by_customer_id' => null, 'used_at' => null]);
            DB::table('vouchers')->where('assigned_to', $customer->id)->update(['assigned_to' => null, 'assigned_at' => null]);
            DB::table('payments')->where('customer_id', $customer->id)->delete();
            DB::table('orders')->where('customer_id', $customer->id)->delete();
            DB::table('subscriptions')->where('customer_id', $customer->id)->delete();
            $customer->purchases()->delete();
            $customer->tokens()->delete();
            $customer->forceDelete();
        });

        ActivityLog::record(
            'customer.test_data_deleted',
            'Super admin permanently removed test data: '.json_encode($summary),
            ['user_id' => $request->user()->id]
        );

        return response()->json([
            'message' => 'Test customer, purchases, payments, sessions, usage logs, and MikroTik account were deleted.',
            'deleted' => $summary,
        ]);
    }
}
