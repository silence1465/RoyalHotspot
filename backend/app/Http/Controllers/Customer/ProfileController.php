<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\HotspotSession;
use App\Models\HotspotUser;
use App\Services\MikrotikServiceFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $customer = $request->user();

        $validated = $request->validate([
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => [
                'sometimes', 'required', 'string', 'max:20',
                Rule::unique('customers', 'phone')->ignore($customer->id),
            ],
            // Password changes deserve their own explicit flow (current
            // password confirmation) rather than sneaking into a general
            // profile PUT — deliberately not included here.
        ]);

        $customer->update($validated);

        return response()->json($customer->fresh());
    }

    public function resetWifiPassword(Request $request, MikrotikServiceFactory $mikrotikFactory)
    {
        $data = $request->validate([
            'router_id' => ['required', 'integer', 'exists:routers,id'],
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9]+$/', 'confirmed'],
        ]);
        $customer = $request->user();

        if (! Hash::check($data['current_password'], $customer->password)) {
            return response()->json([
                'message' => 'The current dashboard password is incorrect.',
                'errors' => ['current_password' => ['The current dashboard password is incorrect.']],
            ], 422);
        }

        $hasActiveAccess = $customer->purchases()
            ->where('router_id', $data['router_id'])
            ->active()
            ->exists();
        if (! $hasActiveAccess) {
            return response()->json(['message' => 'You do not have active Wi-Fi access on this router.'], 403);
        }

        $hotspotUser = HotspotUser::with('router')
            ->where('customer_id', $customer->id)
            ->where('router_id', $data['router_id'])
            ->first();
        if (! $hotspotUser || $hotspotUser->disabled) {
            return response()->json(['message' => 'Your active Wi-Fi account could not be found.'], 404);
        }

        $result = $mikrotikFactory->make($hotspotUser->router)
            ->resetHotspotUserPassword($hotspotUser->username, $data['password']);
        if (! $result['success']) {
            return response()->json([
                'message' => 'The router could not reset your Wi-Fi password. Please try again later.',
            ], 502);
        }

        $hotspotUser->update([
            'password' => $data['password'],
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
            'customer.hotspot_password_self_reset',
            "Customer reset Wi-Fi password on router #{$hotspotUser->router_id}.",
            ['customer_id' => $customer->id]
        );

        return response()->json([
            'message' => 'Wi-Fi password changed. Existing Wi-Fi sessions were disconnected.',
            'username' => $hotspotUser->username,
            'password' => $data['password'],
            'router_id' => $hotspotUser->router_id,
        ]);
    }
}
