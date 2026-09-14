<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MikrotikAdminSecurityService;
use Illuminate\Http\Request;

class MikrotikSecurityController extends Controller
{
    public function status(Request $request, MikrotikAdminSecurityService $security)
    {
        $unlock = $security->current($request);
        return response()->json(['configured' => filled(config('mikrotik_security.key_hash')), 'unlocked' => (bool) $unlock, 'expires_at' => $unlock?->expires_at?->toIso8601String()]);
    }

    public function unlock(Request $request, MikrotikAdminSecurityService $security)
    {
        abort_unless(filled(config('mikrotik_security.key_hash')), 503, 'Router Management security has not been configured.');
        $data = $request->validate(['security_key' => ['required', 'string', 'max:512']]);
        $unlock = $security->unlock($request, $data['security_key']);
        if (! $unlock) return response()->json(['message' => 'The security key is invalid.'], 422);
        return response()->json(['message' => 'Router Management unlocked.', 'unlocked' => true, 'expires_at' => $unlock->expires_at->toIso8601String()]);
    }

    public function lock(Request $request, MikrotikAdminSecurityService $security)
    {
        $security->lock($request);
        return response()->json(['message' => 'Router Management locked.']);
    }
}
