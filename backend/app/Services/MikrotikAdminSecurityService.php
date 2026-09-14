<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\MikrotikAdminUnlock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class MikrotikAdminSecurityService
{
    public function current(Request $request): ?MikrotikAdminUnlock
    {
        $tokenId = $request->user()?->currentAccessToken()?->getKey();
        if (! $tokenId) return null;

        $unlock = MikrotikAdminUnlock::where('user_id', $request->user()->id)
            ->where('personal_access_token_id', $tokenId)->first();
        if ($unlock && $unlock->expires_at->isPast()) {
            $this->audit($request, 'mikrotik.security.expired', 'expired');
            $unlock->delete();
            return null;
        }
        return $unlock;
    }

    public function unlock(Request $request, string $securityKey): ?MikrotikAdminUnlock
    {
        $hash = config('mikrotik_security.key_hash');
        try {
            $valid = is_string($hash) && $hash !== '' && Hash::check($securityKey, $hash);
        } catch (\Throwable) {
            $valid = false;
        }
        if (! $valid) {
            $this->audit($request, 'mikrotik.security.unlock_failed', 'failed');
            return null;
        }
        $now = now();
        $unlock = MikrotikAdminUnlock::updateOrCreate(
            ['personal_access_token_id' => $request->user()->currentAccessToken()->getKey()],
            ['user_id' => $request->user()->id, 'unlocked_at' => $now, 'last_used_at' => $now,
                'expires_at' => $now->copy()->addMinutes(max(1, config('mikrotik_security.unlock_minutes'))),
                'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000)]
        );
        $this->audit($request, 'mikrotik.security.unlocked', 'success');
        return $unlock;
    }

    public function touch(MikrotikAdminUnlock $unlock): void
    {
        $values = ['last_used_at' => now()];
        if (config('mikrotik_security.sliding')) $values['expires_at'] = now()->addMinutes(max(1, config('mikrotik_security.unlock_minutes')));
        $unlock->update($values);
    }

    public function lock(Request $request): void
    {
        $this->current($request)?->delete();
        $this->audit($request, 'mikrotik.security.locked', 'success');
    }

    private function audit(Request $request, string $action, string $outcome): void
    {
        ActivityLog::record($action, json_encode(['outcome' => $outcome, 'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000)], JSON_UNESCAPED_SLASHES), ['user_id' => $request->user()?->id]);
    }

    public function auditManagementAction(Request $request, int $status): void
    {
        $router = $request->route('router');
        ActivityLog::record('mikrotik.management.action', json_encode([
            'method' => $request->method(),
            'path' => $request->path(),
            'router_id' => is_object($router) ? $router->getKey() : $router,
            'outcome' => $status < 400 ? 'success' : 'failed',
            'status' => $status,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
        ], JSON_UNESCAPED_SLASHES), ['user_id' => $request->user()?->id]);
    }
}
