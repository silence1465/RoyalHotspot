<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\HotspotSession;
use App\Models\HotspotUser;
use App\Models\Purchase;
use App\Models\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HotspotSessionService
{
    public function __construct(protected MikrotikServiceFactory $mikrotikFactory) {}

    public function prepare(
        Customer $customer,
        int $routerId,
        string $loginUrl,
        ?string $macAddress,
        ?string $ipAddress
    ): array {
        if ($customer->status === 'suspended') {
            throw ValidationException::withMessages(['account' => 'This account is suspended.']);
        }

        $router = Router::whereKey($routerId)->where('connection_mode', 'live')->first();
        if (! $router) {
            throw ValidationException::withMessages(['router_id' => 'This hotspot is not available for live connections.']);
        }

        $this->validateLoginUrl($router, $loginUrl);

        $lock = Cache::lock("hotspot-connect:{$customer->id}:{$router->id}", 20);

        return $lock->block(5, function () use ($customer, $router, $loginUrl, $macAddress, $ipAddress) {
            $current = $this->current($customer, $router->id);
            if ($current['connected']) {
                return [
                    'session_id' => $current['session']['session_id'],
                    'status' => 'active',
                    'connected' => true,
                ];
            }

            if ($current['status'] === 'unknown') {
                throw new \RuntimeException('The hotspot is temporarily unavailable. Your existing connection was not changed.');
            }

            $purchase = Purchase::where('customer_id', $customer->id)
                ->where('router_id', $router->id)
                ->active()
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->latest('verified_at')
                ->first();

            if (! $purchase) {
                throw ValidationException::withMessages([
                    'subscription' => 'You do not have an active internet package for this hotspot.',
                ]);
            }

            [$username, $password] = $this->credentialsFor($purchase);

            $mikrotik = $this->mikrotikFactory->make($router);
            $activeResult = $mikrotik->getActiveUsers();
            if (! $activeResult['success']) {
                throw new \RuntimeException('Could not check the hotspot right now: '.($activeResult['error'] ?? 'router unavailable'));
            }

            $existingSessions = collect($activeResult['data'])
                ->filter(fn (array $session) => ($session['user'] ?? null) === $username);

            foreach ($existingSessions as $existing) {
                $sessionId = $existing['.id'] ?? null;
                if (! $sessionId) {
                    continue;
                }

                $removeResult = $mikrotik->removeActiveSession($sessionId);
                if (! $removeResult['success']) {
                    throw new \RuntimeException('Could not replace the previous hotspot session: '.($removeResult['error'] ?? 'unknown error'));
                }
            }

            if ($purchase->isLive()) {
                $purchase = app(PurchaseService::class)->activateLiveAccess($purchase);
            }

            HotspotSession::where('customer_id', $customer->id)
                ->where('router_id', $router->id)
                ->whereIn('status', ['connecting', 'active'])
                ->update([
                    'status' => 'replaced',
                    'disconnect_reason' => 'new_connection',
                    'ended_at' => now(),
                ]);

            $session = HotspotSession::create([
                'public_id' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'purchase_id' => $purchase->id,
                'router_id' => $router->id,
                'mikrotik_username' => $username,
                'mac_address' => $this->normalizeMac($macAddress),
                'ip_address' => $ipAddress,
                'status' => 'connecting',
            ]);

            return [
                'session_id' => $session->public_id,
                'status' => $session->status,
                'login_url' => $loginUrl,
                'username' => $username,
                'password' => $password,
                'connected' => false,
            ];
        });
    }

    /**
     * Return the authenticated customer's current connection on one router.
     * A temporary RouterOS failure is deliberately reported as unknown so a
     * known active session is not destroyed or replaced on a false negative.
     */
    public function current(Customer $customer, int $routerId): array
    {
        $session = HotspotSession::where('customer_id', $customer->id)
            ->where('router_id', $routerId)
            ->where('status', 'active')
            ->with('router')
            ->latest('last_seen_at')
            ->latest('id')
            ->first();

        if (! $session) {
            return ['connected' => false, 'status' => 'disconnected', 'session' => null];
        }

        $result = $this->mikrotikFactory->make($session->router)->getActiveUsers();
        if (! $result['success']) {
            return [
                'connected' => null,
                'status' => 'unknown',
                'message' => 'The router could not be checked right now.',
                'session' => $this->sessionPayload($session),
            ];
        }

        $match = collect($result['data'])->first(
            fn (array $active) => $this->matchesSession($session, $active)
        );

        if (! $match) {
            $session->update([
                'status' => 'disconnected',
                'disconnect_reason' => 'router_session_ended',
                'ended_at' => now(),
            ]);

            return ['connected' => false, 'status' => 'disconnected', 'session' => null];
        }

        $session->update([
            'mikrotik_session_id' => $match['.id'] ?? $session->mikrotik_session_id,
            'last_seen_at' => now(),
        ]);

        return [
            'connected' => true,
            'status' => 'active',
            'session' => $this->sessionPayload($session->fresh()),
        ];
    }

    public function confirm(Customer $customer, HotspotSession $session): HotspotSession
    {
        if ($session->customer_id !== $customer->id) {
            abort(404);
        }

        if ($session->status === 'active' || ! in_array($session->status, ['connecting'], true)) {
            return $session;
        }

        if ($session->created_at->lt(now()->subMinute())) {
            $session->update([
                'status' => 'failed',
                'ended_at' => now(),
                'failure_message' => 'WiFi connection failed. Your hotspot account could not be authenticated. Please try again.',
            ]);

            return $session->fresh();
        }

        $result = $this->mikrotikFactory->make($session->router)->getActiveUsers();
        if (! $result['success']) {
            return $session;
        }

        $match = collect($result['data'])->first(
            fn (array $active) => $this->matchesSession($session, $active)
        );

        if ($match) {
            $session->update([
                'status' => 'active',
                'mikrotik_session_id' => $match['.id'] ?? null,
                'started_at' => now(),
                'last_seen_at' => now(),
                'failure_message' => null,
            ]);
        }

        return $session->fresh();
    }

    protected function credentialsFor(Purchase $purchase): array
    {
        if ($purchase->isVoucher()) {
            $code = $purchase->voucher?->code;
            if (! $code) {
                throw ValidationException::withMessages(['subscription' => 'The voucher for this purchase is not available.']);
            }

            app(PurchaseService::class)->activateVoucherAccess($purchase);

            return [$code, $code];
        }

        $hotspotUser = HotspotUser::where('customer_id', $purchase->customer_id)
            ->where('router_id', $purchase->router_id)
            ->first();

        if (! $hotspotUser || $hotspotUser->disabled || ! $hotspotUser->mikrotik_user_id) {
            throw ValidationException::withMessages(['subscription' => 'Your hotspot account is not ready yet. Please try again shortly.']);
        }

        return [$hotspotUser->username, $hotspotUser->makeVisible('password')->password];
    }

    protected function validateLoginUrl(Router $router, string $loginUrl): void
    {
        $scheme = strtolower((string) parse_url($loginUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($loginUrl, PHP_URL_HOST));
        $user = parse_url($loginUrl, PHP_URL_USER);
        $pass = parse_url($loginUrl, PHP_URL_PASS);
        $port = parse_url($loginUrl, PHP_URL_PORT);
        $configuredHost = strtolower(trim((string) $router->hotspot_login_host));

        if (! in_array($scheme, ['http', 'https'], true) || ! $host || $user || $pass
            || ($port && ! in_array($port, [80, 443], true))) {
            throw ValidationException::withMessages(['login_url' => 'The hotspot login URL is invalid.']);
        }

        if (! $configuredHost) {
            throw ValidationException::withMessages([
                'login_url' => 'Automatic connection is not configured for this router yet. Ask an administrator to set its hotspot login host.',
            ]);
        }

        if (! hash_equals($configuredHost, $host)) {
            throw ValidationException::withMessages([
                'login_url' => "The hotspot login host is {$host}, but this router is configured for {$configuredHost}. Update the router's Hotspot Login Host to the host shown here if it is correct.",
            ]);
        }
    }

    protected function normalizeMac(?string $mac): ?string
    {
        return $mac ? strtoupper(str_replace('-', ':', trim($mac))) : null;
    }

    protected function matchesSession(HotspotSession $session, array $active): bool
    {
        if (($active['user'] ?? null) !== $session->mikrotik_username) {
            return false;
        }

        if ($session->mac_address && $this->normalizeMac($active['mac-address'] ?? null) !== $session->mac_address) {
            return false;
        }

        return ! $session->ip_address || ($active['address'] ?? null) === $session->ip_address;
    }

    protected function sessionPayload(HotspotSession $session): array
    {
        return [
            'session_id' => $session->public_id,
            'status' => $session->status,
            'mikrotik_username' => $session->mikrotik_username,
            'mikrotik_session_id' => $session->mikrotik_session_id,
            'started_at' => $session->started_at,
            'last_seen_at' => $session->last_seen_at,
        ];
    }
}
