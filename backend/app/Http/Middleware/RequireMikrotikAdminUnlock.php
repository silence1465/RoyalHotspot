<?php

namespace App\Http\Middleware;

use App\Services\MikrotikAdminSecurityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireMikrotikAdminUnlock
{
    public function __construct(private readonly MikrotikAdminSecurityService $security)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $unlock = $this->security->current($request);
        if (! $unlock) return response()->json(['message' => 'Router Management is locked.', 'code' => 'MIKROTIK_ADMIN_LOCKED'], 423);
        $this->security->touch($unlock);
        $response = $next($request);
        $this->security->auditManagementAction($request, $response->getStatusCode());
        return $response;
    }
}
