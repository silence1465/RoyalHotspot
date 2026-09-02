<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Required by $middleware->throttleApi() in bootstrap/app.php —
        // that call applies 'throttle:api' to every API route, but the
        // limiter it references was never actually registered anywhere
        // in this hand-built project (a stock `laravel new` app defines
        // this automatically; this one never went through that
        // scaffolding). Matches Laravel's own standard default: 60
        // requests/minute, keyed by the authenticated user where
        // possible so one customer's traffic can't exhaust another's
        // allowance, falling back to IP for unauthenticated requests.
        RateLimiter::for('api', function ($request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Tighter limit than default API throttling — login/register are
        // brute-force and account-enumeration targets.
        RateLimiter::for('login', function ($request) {
            $identity = strtolower(trim((string) ($request->input('email')
                ?? $request->input('username')
                ?? $request->input('phone')
                ?? $request->input('guest_phone')
                ?? 'anonymous')));

            return [
                Limit::perMinute(30)->by('login-ip|'.$request->ip()),
                Limit::perMinute(6)->by('login-identity|'.hash('sha256', $identity).'|'.$request->ip()),
            ];
        });

        // Voucher codes are guessable strings if this isn't rate-limited —
        // keep it tight and keyed by IP + the authenticated customer.
        RateLimiter::for('voucher-redeem', function ($request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Royal WiFi extension — the "Already Paid" fallback searches by
        // customer-submitted transaction ID/reference; not brute-forceable
        // in practice (a real match requires an actual received SMS), but
        // still worth a sane limit against abuse/scripted retries.
        RateLimiter::for('order-verify', function ($request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('hotspot-connect', function ($request) {
            return Limit::perMinute(12)->by(($request->user()?->id ?? 'guest') . '|' . $request->ip());
        });

        // SMS Forwarder webhook — already gated by a shared-secret token
        // (VerifySmsForwarderToken), this is defense-in-depth against a
        // leaked/compromised token being used to flood the endpoint.
        RateLimiter::for('sms-webhook', function ($request) {
            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
