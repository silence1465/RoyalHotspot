<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::query();

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

    public function show(Customer $customer)
    {
        return response()->json(
            $customer->load([
                'subscriptions' => fn ($q) => $q->with(['package:id,name', 'router:id,name'])->latest(),
                'payments' => fn ($q) => $q->latest()->take(20),
                'hotspotUsers.router:id,name',
            ])
        );
    }
}
