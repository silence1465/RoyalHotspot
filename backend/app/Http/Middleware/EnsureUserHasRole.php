<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Customer;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();
        $allowedRoles = [];

        foreach ($roles as $role) {
            if (! is_string($role) && ! is_numeric($role)) {
                continue;
            }

            foreach (explode(',', (string) $role) as $item) {
                $item = trim($item);

                if ($item !== '') {
                    $allowedRoles[] = $item;
                }
            }
        }

        $allowedRoles = array_values(array_unique($allowedRoles));

        Log::debug('EnsureUserHasRole', [
            'user' => $user ? ['id' => $user->id, 'role' => $user->role] : null,
            'allowed_roles' => $allowedRoles,
        ]);

        if (! $user || empty($allowedRoles)) {
            abort(403, 'Unauthorized.');
        }

        // If the authenticated principal is a Customer (separate model
        // from admin `User`) then allow when the route expects the
        // `customer` role. Customer records do not have a `role` field.
        if ($user instanceof Customer) {
            if (in_array('customer', $allowedRoles, true)) {
                return $next($request);
            }
            abort(403, 'Unauthorized.');
        }

        if (! in_array($user->role ?? null, $allowedRoles, true)) {
            abort(403, 'Unauthorized.');
        }

        return $next($request);
    }
}
