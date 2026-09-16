<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HotspotUser;
use App\Models\Purchase;
use App\Models\Router;
use App\Services\MikrotikService;
use Illuminate\Http\Request;

class ActiveUsersController extends Controller
{
    /**
     * Cumulative bandwidth only (bytes in/out reported directly by
     * RouterOS) — no real-time current-speed polling, since that would
     * need repeated sampling over time to compute a rate, not something
     * a single request can give. Only meaningful for live routers —
     * manual routers have zero visibility into this by definition.
     */
    public function index(Request $request)
    {
        $routerId = $request->query('router_id');
        $routerQuery = Router::where('connection_mode', 'live');
        $allowedRouterIds = $request->attributes->get('admin_router_ids');
        if ($allowedRouterIds !== null) {
            $routerQuery->whereIn('id', $allowedRouterIds);
        }
        if ($routerId) {
            $routerQuery->whereKey($routerId);
        }
        $routers = $routerQuery->get();

        $sessions = [];

        foreach ($routers as $router) {
            $result = $this->activeUsersFor($router);

            if (! $result['success']) {
                continue; // router unreachable right now — skip, don't fail the whole request
            }

            foreach ($result['data'] as $session) {
                $sessions[] = [
                    'session_id' => $session['.id'] ?? null,
                    'router' => $router->name,
                    'router_id' => $router->id,
                    'username' => $session['user'] ?? null,
                    'address' => $session['address'] ?? null,
                    'uptime' => $session['uptime'] ?? null,
                    'bytes_in' => (int) ($session['bytes-in'] ?? 0),
                    'bytes_out' => (int) ($session['bytes-out'] ?? 0),
                ];
            }
        }

        $routerIds = collect($sessions)->pluck('router_id')->unique()->values();
        $usernames = collect($sessions)->pluck('username')->filter()->unique()->values();

        $hotspotUsers = HotspotUser::whereIn('router_id', $routerIds)
            ->whereIn('username', $usernames)
            ->get()
            ->keyBy(fn ($user) => "{$user->router_id}|{$user->username}");

        $purchases = Purchase::active()
            ->whereIn('router_id', $routerIds)
            ->with(['package:id,name', 'voucher:id,code'])
            ->orderByDesc('starts_at')
            ->get();

        $sessions = collect($sessions)->map(function (array $session) use ($hotspotUsers, $purchases) {
            $username = $session['username'];
            $hotspotUser = $hotspotUsers->get("{$session['router_id']}|{$username}");

            $purchase = $purchases->first(function (Purchase $candidate) use ($session, $username, $hotspotUser) {
                if ((int) $candidate->router_id !== (int) $session['router_id']) {
                    return false;
                }

                if ($hotspotUser && (int) $candidate->customer_id === (int) $hotspotUser->customer_id) {
                    return true;
                }

                return $candidate->guest_code === $username || $candidate->voucher?->code === $username;
            });

            return array_merge($session, [
                'package_name' => $purchase?->package?->name,
                'started_at' => $purchase?->starts_at?->toIso8601String(),
                'expires_at' => $purchase?->expires_at?->toIso8601String(),
            ]);
        })->values()->all();

        return response()->json(['sessions' => $sessions]);
    }

    protected function activeUsersFor(Router $router): array
    {
        return (new MikrotikService($router))->getActiveUsers();
    }

    /**
     * Kick a specific active session — see
     * MikrotikService::removeActiveSession() for exactly what this
     * does and doesn't do (disconnects now, doesn't touch the account).
     */
    public function resetSession(Request $request)
    {
        $validated = $request->validate([
            'router_id' => ['required', 'integer', 'exists:routers,id'],
            'session_id' => ['required', 'string'],
        ]);

        $router = Router::findOrFail($validated['router_id']);
        $mikrotik = new MikrotikService($router);
        $result = $mikrotik->removeActiveSession($validated['session_id']);

        if (! $result['success']) {
            return response()->json([
                'message' => 'Could not reset session: '.($result['error'] ?? 'unknown error'),
            ], 502);
        }

        return response()->json(['message' => 'Session reset — device will need to log in again.']);
    }
}
