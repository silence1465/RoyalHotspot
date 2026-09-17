<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IspSessionSnapshot;
use App\Models\Router;
use App\Services\WanSessionMonitorService;
use App\Support\AdminRouterScope;
use Illuminate\Http\Request;

class IspSessionController extends Controller
{
    public function index(Request $request, Router $router)
    {
        $this->authorizeRouter($request, $router);
        $range = $request->validate(['range' => ['nullable', 'in:today,24h,7d,30d']])['range'] ?? '24h';
        $from = match ($range) {
            'today' => now()->startOfDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDay(),
        };

        $isps = $router->isps()->with('latestSessionSnapshot')->get();
        $history = IspSessionSnapshot::where('router_id', $router->id)
            ->where('recorded_at', '>=', $from)
            ->orderBy('recorded_at')
            ->get()
            ->groupBy('router_isp_id');

        return response()->json(['isps' => $isps, 'history' => $history, 'range' => $range]);
    }

    public function refresh(Request $request, Router $router, WanSessionMonitorService $monitor)
    {
        $this->authorizeRouter($request, $router);
        $result = $monitor->poll($router);

        return response()->json($result, $result['success'] ? 200 : 502);
    }

    protected function authorizeRouter(Request $request, Router $router): void
    {
        $ids = AdminRouterScope::ids($request);
        abort_if($ids !== null && ! in_array($router->id, $ids, true), 403);
    }
}
