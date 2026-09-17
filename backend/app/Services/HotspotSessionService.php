<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\HotspotSession;
use App\Models\HotspotUser;
use App\Models\Purchase;
use App\Models\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        $lock = Cache::lock("hotspot-connect:{$customer->id}:{$router->id}", 120);

        return $lock->block(5, function () use ($customer, $router, $loginUrl, $macAddress, $ipAddress) {
            $purchase = $this->eligiblePurchase($customer, $router->id);
            if (! $purchase) {
                throw ValidationException::withMessages([
                    'subscription' => 'You do not have an active internet package for this hotspot.',
                ]);
            }

            $current = $this->currentForPurchase($customer, $router, $purchase);
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

            [$username, $password] = $this->credentialsFor($purchase);

            $pending = HotspotSession::where('customer_id', $customer->id)
                ->where('router_id', $router->id)
                ->where('purchase_id', $purchase->id)
                ->where('status', 'connecting')
                ->where('created_at', '>=', now()->subMinute())
                ->latest('id')->first();
            if ($pending) {
                if ($pending->mac_address !== $this->normalizeMac($macAddress) || $pending->ip_address !== $ipAddress) {
                    throw ValidationException::withMessages(['connection' => 'A connection is already being established on another device. Please wait.']);
                }

                return [
                    'session_id' => $pending->public_id, 'status' => 'connecting',
                    'login_url' => $loginUrl, 'username' => $username,
                    'password' => $password, 'connected' => false,
                ];
            }

            $mikrotik = $this->mikrotikFactory->make($router);
            $activeResult = $mikrotik->getActiveUsers();
            if (! $activeResult['success']) {
                throw new \RuntimeException('Could not check the hotspot right now: '.($activeResult['error'] ?? 'router unavailable'));
            }

            $existingSessions = collect($activeResult['data'])
                ->filter(fn (array $session) => ($session['user'] ?? null) === $username);

            if ($existingSessions->contains(fn (array $existing) => ! empty($existing['.id']))) {
                $removeResult = $mikrotik->disconnectAndClearHotspotCookies($username, $this->normalizeMac($macAddress));
                if (! $removeResult['success']) {
                    throw new \RuntimeException('Could not replace the previous hotspot session: '.($removeResult['error'] ?? 'unknown error'));
                }
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
        $router = Router::whereKey($routerId)->where('connection_mode', 'live')->first();
        if (! $router) {
            return ['connected' => false, 'status' => 'disconnected', 'session' => null];
        }

        return Cache::lock("hotspot-connect:{$customer->id}:{$routerId}", 120)->block(5,
            fn () => $this->currentForPurchase($customer, $router, $this->eligiblePurchase($customer, $routerId))
        );
    }

    protected function currentForPurchase(Customer $customer, Router $router, ?Purchase $purchase): array
    {
        $session = HotspotSession::where('customer_id', $customer->id)
            ->where('router_id', $router->id)
            ->where('status', 'active')
            ->with(['router', 'purchase'])
            ->latest('last_seen_at')
            ->latest('id')
            ->first();

        if (! $session) {
            return ['connected' => false, 'status' => 'disconnected', 'session' => null];
        }

        $sessionPurchase = $session->purchase;
        $legitimate = $customer->status !== 'suspended' && $purchase
            && (int) $session->purchase_id === (int) $purchase->id
            && $sessionPurchase
            && $sessionPurchase->isActive()
            && $sessionPurchase->starts_at !== null
            && $sessionPurchase->expires_at !== null
            && $sessionPurchase->expires_at->isFuture();

        if (! $legitimate) {
            $mikrotik = $this->mikrotikFactory->make($session->router);
            $result = $mikrotik->disconnectAndClearHotspotCookies($session->mikrotik_username, $session->mac_address);
            if (! $result['success']) {
                return [
                    'connected' => null,
                    'status' => 'unknown',
                    'message' => 'The stale hotspot connection could not be removed right now.',
                    'session' => $this->sessionPayload($session),
                ];
            }

            $session->update([
                'status' => $sessionPurchase?->isExpiredByTime() ? 'expired' : 'replaced',
                'disconnect_reason' => $sessionPurchase?->isExpiredByTime() ? 'purchase_expired' : 'purchase_replaced',
                'ended_at' => now(),
            ]);

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

    protected function eligiblePurchase(Customer $customer, int $routerId): ?Purchase
    {
        return Purchase::where('customer_id', $customer->id)
            ->where('router_id', $routerId)
            ->active()
            ->where(function ($query) {
                $query->where(function ($started) {
                    $started->whereNotNull('starts_at')
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '>', now());
                })->orWhere(function ($waiting) {
                    $waiting->where('fulfillment_type', 'live')
                        ->whereNull('starts_at')
                        ->whereNull('expires_at');
                })->orWhere(function ($voucher) {
                    $voucher->where('fulfillment_type', 'voucher')
                        ->where(function ($expiry) {
                            $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        });
                });
            })
            ->orderByRaw('CASE WHEN starts_at IS NOT NULL THEN 0 ELSE 1 END')
            ->latest('verified_at')
            ->latest('id')
            ->first();
    }

    public function confirm(Customer $customer, HotspotSession $session): HotspotSession
    {
        abort_unless((int) $session->customer_id === (int) $customer->id, 404);

        return Cache::lock("hotspot-connect:{$customer->id}:{$session->router_id}", 120)
            ->block(5, fn () => $this->confirmLocked($customer, $session->fresh()));
    }

    protected function confirmLocked(Customer $customer, HotspotSession $session): HotspotSession
    {
        if ($session->customer_id !== $customer->id) {
            abort(404);
        }

        if (! in_array($session->status, ['connecting', 'active'], true)) {
            return $session;
        }

        $purchase = $this->eligiblePurchase($customer, $session->router_id);
        if ($customer->status === 'suspended' || ! $purchase || (int) $purchase->id !== (int) $session->purchase_id) {
            $result = $this->mikrotikFactory->make($session->router)
                ->disconnectAndClearHotspotCookies($session->mikrotik_username, $session->mac_address);
            if (! $result['success']) {
                throw new \RuntimeException('Could not revoke the invalid hotspot connection. Please retry.');
            }
            $session->update(['status' => 'expired', 'disconnect_reason' => 'purchase_not_eligible', 'ended_at' => now()]);

            return $session->fresh();
        }

        if ($session->status === 'active') {
            $this->currentForPurchase($customer, $session->router, $purchase);

            return $session->fresh();
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
            DB::transaction(function () use ($session, $purchase, $match) {
                $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
                if (! $locked->isActive() || $locked->isExpiredByTime()) {
                    throw new \RuntimeException('The package is no longer eligible for connection.');
                }
                if ($locked->isLive()) {
                    app(PurchaseService::class)->activateLiveAccess($locked);
                }
                $session->update([
                    'status' => 'active',
                    'mikrotik_session_id' => $match['.id'] ?? null,
                    'started_at' => now(),
                    'last_seen_at' => now(),
                    'failure_message' => null,
                ]);
            });
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
