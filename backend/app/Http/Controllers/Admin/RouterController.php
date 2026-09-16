<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RouterRequest;
use App\Models\ActivityLog;
use App\Models\Router;
use App\Services\MikrotikService;
use App\Support\AdminRouterScope;
use Illuminate\Http\Request;

class RouterController extends Controller
{
    public function index(Request $request)
    {
        $query = Router::query();
        AdminRouterScope::apply($query, $request, 'id');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhere('wireguard_ip', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json(
            $query->latest()->paginate($request->integer('per_page', 10))
        );
    }

    public function store(RouterRequest $request)
    {
        $data = $request->validated();
        $data['api_port'] = $data['api_port'] ?? 8729;
        $data['api_ssl'] = $data['api_ssl'] ?? true;
        $data['status'] = $data['status'] ?? 'offline';
        $data['connection_mode'] = $data['connection_mode'] ?? 'live';

        $router = Router::create($data);

        ActivityLog::record('router.created', "Router '{$router->name}' created.", ['user_id' => $request->user()->id]);

        return response()->json($router, 201);
    }

    public function show(Router $router)
    {
        return response()->json($router);
    }

    public function update(RouterRequest $request, Router $router)
    {
        $data = $request->validated();

        // Blank password on update means "don't change it" — never let an
        // empty string overwrite the real encrypted credential.
        if (! filled($data['api_password'] ?? null)) {
            unset($data['api_password']);
        }
        if (! filled($data['provisioning_api_password'] ?? null)) {
            unset($data['provisioning_api_password']);
        }

        $router->update($data);

        ActivityLog::record('router.updated', "Router '{$router->name}' updated.", ['user_id' => $request->user()->id]);

        return response()->json($router->fresh());
    }

    public function destroy(Request $request, Router $router)
    {
        // Soft delete only — a hard delete would orphan hotspot_users and
        // purchases rows still referencing this router (see
        // docs/DATABASE_SCHEMA.md). Block it outright if there are active
        // purchases still pointing at this router; an admin should
        // migrate/cancel those first rather than delete out from under them.
        // Purchase::active() covers every active-equivalent status
        // (active, voucher_assigned, completed), not just 'active' —
        // a single-status check here would have missed voucher-fulfilled
        // purchases entirely.
        $activePurchases = $router->purchases()->active()->count();

        if ($activePurchases > 0) {
            return response()->json([
                'message' => "Cannot delete: {$activePurchases} active purchase(s) still use this router.",
            ], 422);
        }

        $router->delete();

        ActivityLog::record('router.deleted', "Router '{$router->name}' deleted.", ['user_id' => $request->user()->id]);

        return response()->json(['message' => 'Router deleted.']);
    }

    public function testConnection(Request $request, Router $router)
    {
        if ($router->isManual()) {
            return response()->json([
                'success' => false,
                'message' => 'This router is set to Manual mode — there\'s no live connection to test. Switch it to Live mode once VPS/WireGuard access is available.',
                'data' => null,
            ], 422);
        }

        $mikrotik = new MikrotikService($router);
        $result = $mikrotik->testConnection();

        $router->update(['status' => $result['success'] ? 'online' : 'offline']);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['success']
                ? 'Connected successfully.'
                : ('Connection failed: '.($result['error'] ?? 'unknown error')),
            'data' => $result['data'],
        ], $result['success'] ? 200 : 502);
    }

    /**
     * Deliberately a separate, explicit action rather than something
     * that fires automatically on save — this rewrites the router's own
     * hotspot login page and touches its walled-garden firewall rules,
     * which is meaningfully more invasive than "Test Connection" and
     * worth an admin triggering on purpose with a clear result, and
     * re-runnable later if the portal page setup ever needs updating.
     */
    public function setupGuestPortal(Router $router)
    {
        if ($router->isManual()) {
            return response()->json([
                'success' => false,
                'message' => 'This router is set to Manual mode — guest portal setup requires a live connection.',
            ], 422);
        }

        if (! $router->provisioning_api_username || ! $router->provisioning_api_password) {
            return response()->json([
                'success' => false,
                'message' => 'No provisioning credentials set up for this router yet. Add them under Edit Router first — see docs/PRODUCTION_SECURITY.md for why this is a separate credential from the regular one.',
            ], 422);
        }

        $mikrotik = new MikrotikService($router);
        $backendUrl = rtrim(config('services.backend.url'), '/');
        $frontendUrl = rtrim(config('services.frontend.url'), '/');
        $backendHost = parse_url($backendUrl, PHP_URL_HOST);
        $frontendHost = parse_url($frontendUrl, PHP_URL_HOST);

        // Both hosts need to be reachable by an unauthenticated device —
        // the backend so the router itself can /tool fetch the login
        // page, and the frontend since that's what actually renders
        // /portal and /guest/buy for the guest's browser. Missing either
        // one leaves the guest device blocked by the hotspot's own
        // firewall before it can load anything at all.
        $walledGardenResult = $mikrotik->addWalledGardenEntry($backendHost);

        if (! $walledGardenResult['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Could not add walled-garden entry for the backend: '.($walledGardenResult['error'] ?? 'unknown error'),
            ], 502);
        }

        // Only add a second entry if the frontend is genuinely on a
        // different host — some production setups may serve both
        // through the same domain, in which case one entry is enough
        // and a duplicate would just be redundant, not harmful, but no
        // reason to make an extra API call for it.
        if ($frontendHost && $frontendHost !== $backendHost) {
            $frontendWalledGardenResult = $mikrotik->addWalledGardenEntry($frontendHost);

            if (! $frontendWalledGardenResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Backend walled-garden entry added, but the frontend entry failed: '.($frontendWalledGardenResult['error'] ?? 'unknown error'),
                ], 502);
            }
        }

        $loginPageUrl = "{$backendUrl}/api/v1/mikrotik/login/{$router->id}";
        $fetchResult = $mikrotik->fetchLoginPage($loginPageUrl);

        if (! $fetchResult['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Walled garden entry added, but fetching the login page failed: '.($fetchResult['error'] ?? 'unknown error'),
            ], 502);
        }

        ActivityLog::record(
            'router.guest_portal_setup',
            "Guest portal set up for router '{$router->name}' — walled garden entry added, login page fetched.",
            []
        );

        return response()->json([
            'success' => true,
            'message' => 'Guest portal set up successfully. Test it by connecting a device to this hotspot.',
        ]);
    }
}
