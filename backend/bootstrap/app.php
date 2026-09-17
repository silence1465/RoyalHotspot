<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\RequireAdminPermission;
use App\Http\Middleware\RequireMikrotikAdminUnlock;
use App\Http\Middleware\ResolveAdminScope;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifySmsForwarderToken;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        // Snapshot before expiry so the last minute of a short session is
        // captured before the account is disabled and disconnected.
        $schedule->command('bandwidth:snapshot')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Unified purchase expiry covers stale unpaid purchases and active
        // subscriptions whose access window has ended.
        $schedule->command('purchases:expire')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('capacity:reconcile')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Router health — 2 minutes strikes a balance between catching
        // an outage quickly and not hammering every live router with a
        // connection attempt every 60 seconds.
        $schedule->command('routers:monitor')
            ->everyTwoMinutes()
            ->withoutOverlapping()
            ->runInBackground();
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(SecurityHeaders::class);
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->api(prepend: [
            // Standard Sanctum token auth (Authorization: Bearer <token>).
            // We are NOT using Sanctum's stateful-SPA cookie mode here —
            // this is a plain token API, so EnsureFrontendRequestsAreStateful
            // is deliberately not registered.
        ]);

        // IMPORTANT: `auth:admin` / `auth:customer` alone are NOT a real
        // security boundary with Sanctum — Sanctum's guard resolves the
        // authenticated user from the token's polymorphic `tokenable`
        // relation regardless of which guard/provider you check against.
        // A customer's token would pass an `auth:admin` check unless we
        // also gate on token abilities. These aliases enforce that:
        // routes use ['auth:admin', 'abilities:admin'] or
        // ['auth:customer', 'abilities:customer'] — see routes/api.php.
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'role' => EnsureUserHasRole::class,
            // Royal WiFi extension — shared-secret auth for the SMS
            // Forwarder webhook, a machine actor that doesn't fit the
            // customer/admin Sanctum abilities at all.
            'sms-forwarder' => VerifySmsForwarderToken::class,
            'mikrotik-unlocked' => RequireMikrotikAdminUnlock::class,
            'admin-scope' => ResolveAdminScope::class,
            'admin-permission' => RequireAdminPermission::class,
        ]);

        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function ($request, $exception) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
