<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function show(Request $request)
    {
        $customer = $request->user();

        $subscription = $customer->subscriptions()
            ->with(['package', 'router:id,name,location'])
            ->latest()
            ->first();

        if (! $subscription) {
            return response()->json(['message' => 'No subscription found.'], 404);
        }

        return response()->json($subscription);
    }
}
