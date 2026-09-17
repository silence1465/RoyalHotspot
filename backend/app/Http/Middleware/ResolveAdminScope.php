<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveAdminScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && $user->status === 'active', 403, 'This administrator account is inactive.');
        $selected = (string) $request->header('X-Router-Id', 'all');
        $assignedIds = $user->isSuperAdmin()
            ? null
            : $user->routers()->pluck('routers.id')->map(fn ($id) => (int) $id)->all();

        if ($selected !== 'all') {
            if (! ctype_digit($selected) || ! $user->canAccessRouter((int) $selected)) {
                abort(403, 'You do not have access to the selected router.');
            }
            $routerIds = [(int) $selected];
        } else {
            $routerIds = $assignedIds;
        }

        $routeRouter = $request->route('router');
        if ($routeRouter) {
            $routeRouterId = (int) (is_object($routeRouter) ? $routeRouter->getKey() : $routeRouter);
            if (! $user->canAccessRouter($routeRouterId)) {
                abort(403, 'You do not have access to this router.');
            }
        }

        // Protect resources whose URL does not contain {router}. Without this,
        // a permitted admin could request another router's purchase by ID.
        foreach (['purchase', 'voucher', 'campaign', 'batch'] as $parameter) {
            $resource = $request->route($parameter);
            if (is_object($resource) && isset($resource->router_id)
                && ! $user->canAccessRouter((int) $resource->router_id)) {
                abort(403, 'You do not have access to this resource.');
            }
        }

        if ($request->filled('router_id') && ! $user->canAccessRouter($request->integer('router_id'))) {
            abort(403, 'You do not have access to this router.');
        }

        $request->attributes->set('admin_router_ids', $routerIds);
        $request->attributes->set('admin_selected_router', $selected);

        return $next($request);
    }
}
