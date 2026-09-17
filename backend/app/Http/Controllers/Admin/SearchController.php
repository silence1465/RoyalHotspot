<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Voucher;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        if (strlen($q) < 2) {
            return response()->json(['customers' => [], 'purchases' => [], 'vouchers' => []]);
        }

        $ids = $request->attributes->get('admin_router_ids');
        $user = $request->user();
        $methods = array_values(array_filter(['paystack', 'momo'], fn ($method) => $user->hasPermission("transactions.{$method}.view")));
        $customers = Customer::where(fn ($query) => $query->where('full_name', 'like', "%{$q}%")
            ->orWhere('phone', 'like', "%{$q}%")
            ->orWhere('username', 'like', "%{$q}%"))
            ->when(! $user->hasPermission('customers.view'), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($ids !== null, fn ($query) => $query->whereHas('purchases', fn ($p) => $p->whereIn('router_id', $ids)))
            ->limit(5)
            ->get(['id', 'full_name', 'phone', 'username']);

        $purchases = Purchase::where(fn ($query) => $query->where('reference', 'like', "%{$q}%")
            ->orWhere('momo_transaction_id', 'like', "%{$q}%"))
            ->when(! $user->isSuperAdmin(), fn ($query) => $query->whereIn('payment_method', $methods))
            ->when($ids !== null, fn ($query) => $query->whereIn('router_id', $ids))
            ->with('customer:id,full_name')
            ->limit(5)
            ->get(['id', 'customer_id', 'reference', 'status', 'amount']);

        $vouchers = Voucher::where('code', 'like', "%{$q}%")
            ->when(! $user->hasPermission('vouchers.view'), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($ids !== null, fn ($query) => $query->whereIn('router_id', $ids))
            ->limit(5)
            ->get(['id', 'code', 'status']);

        return response()->json([
            'customers' => $customers,
            'purchases' => $purchases,
            'vouchers' => $vouchers,
        ]);
    }
}
