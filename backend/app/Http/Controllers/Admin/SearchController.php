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

        $customers = Customer::where('full_name', 'like', "%{$q}%")
            ->orWhere('phone', 'like', "%{$q}%")
            ->orWhere('username', 'like', "%{$q}%")
            ->limit(5)
            ->get(['id', 'full_name', 'phone', 'username']);

        $purchases = Purchase::where('reference', 'like', "%{$q}%")
            ->orWhere('momo_transaction_id', 'like', "%{$q}%")
            ->with('customer:id,full_name')
            ->limit(5)
            ->get(['id', 'customer_id', 'reference', 'status', 'amount']);

        $vouchers = Voucher::where('code', 'like', "%{$q}%")
            ->limit(5)
            ->get(['id', 'code', 'status']);

        return response()->json([
            'customers' => $customers,
            'purchases' => $purchases,
            'vouchers' => $vouchers,
        ]);
    }
}
