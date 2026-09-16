<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\AdminRouterScope;
use Illuminate\Http\Request;

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

        return response()->json(
            $customer->load([
                'subscriptions' => fn ($q) => $q->when($routerIds !== null, fn ($s) => $s->whereIn('router_id', $routerIds))->with(['package:id,name', 'router:id,name'])->latest(),
                'payments' => fn ($q) => $q->when($routerIds !== null, fn ($payments) => $payments->whereHas('purchase', fn ($p) => $p->whereIn('router_id', $routerIds)))->latest()->take(20),
                'hotspotUsers' => fn ($q) => $q->when($routerIds !== null, fn ($users) => $users->whereIn('router_id', $routerIds))->with('router:id,name'),
            ])
        );
    }
}
