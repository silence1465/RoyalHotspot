<?php

namespace App\Services;

use App\Models\Router;
use App\Models\MikrotikLog;
use RouterOS\Client;
use RouterOS\Query;
use Throwable;

/**
 * MikrotikService
 *
 * Talks to a MikroTik router's RouterOS API over the WireGuard tunnel
 * (router.wireguard_ip). Every public method here should:
 *   1. Decrypt the router's api_password ONLY inside this class — never
 *      return it, log it, or pass it back up to a controller.
 *   2. Wrap the RouterOS call in try/catch and log to mikrotik_logs via
 *      logAction(), with sensitive fields redacted before storage.
 *   3. Return a plain array shape: ['success' => bool, 'data' => ..., 'error' => ...]
 *      so controllers/jobs never need to know about RouterOS\Client internals.
 *
 * NOTE ON PERFORMANCE: this implementation opens a fresh API-SSL connection
 * per call rather than pooling connections. That's the safer default (a
 * stale pooled connection after a router reboot is a worse failure mode
 * than a slightly slower reconnect) but revisit if the expiry command
 * (subscriptions:expire) starts running slow against a large active-user
 * count on a single router.
 */
class MikrotikService
{
    protected Router $router;

    public function __construct(Router $router)
    {
        $this->router = $router;
    }

    /**
     * Open a connection using the day-to-day hotspot-management user.
     * Throws on failure — callers should catch.
     */
    protected function connect(): Client
    {
        return new Client([
            'host'     => $this->router->wireguard_ip,
            'user'     => $this->router->api_username,
            'pass'     => decrypt($this->router->api_password),
            'port'     => $this->router->api_port ?? 8729,
            'ssl'      => $this->router->api_ssl ?? true,
            'timeout'  => (int) env('MIKROTIK_CONNECTION_TIMEOUT', 5),
        ]);
    }

    /**
     * Open a connection using the SEPARATE, broader-permission
     * provisioning user (/tool fetch, file write) — see
     * 2024_02_01_000019_add_provisioning_credentials_to_routers for why
     * this is a distinct credential rather than widening the regular
     * one. Only ever used by setupGuestPortal() actions.
     */
    protected function connectProvisioning(): Client
    {
        return new Client([
            'host'     => $this->router->wireguard_ip,
            'user'     => $this->router->provisioning_api_username,
            'pass'     => decrypt($this->router->provisioning_api_password),
            'port'     => $this->router->api_port ?? 8729,
            'ssl'      => $this->router->api_ssl ?? true,
            'timeout'  => (int) env('MIKROTIK_CONNECTION_TIMEOUT', 5),
        ]);
    }

    public function testConnection(): array
    {
        return $this->run('test-connection', function (Client $client) {
            $query = new Query('/system/resource/print');
            return $client->query($query)->read();
        });
    }

    public function getSystemResource(): array
    {
        return $this->run('get-system-resource', function (Client $client) {
            $query = new Query('/system/resource/print');
            return $client->query($query)->read();
        });
    }

    public function getHotspotUsers(): array
    {
        return $this->run('get-hotspot-users', function (Client $client) {
            $query = new Query('/ip/hotspot/user/print');
            return $client->query($query)->read();
        });
    }

    public function getActiveUsers(): array
    {
        return $this->run('get-active-users', function (Client $client) {
            $query = new Query('/ip/hotspot/active/print');
            return $client->query($query)->read();
        });
    }

    /**
     * Kick a currently-connected device off the hotspot — disconnects
     * the active session immediately, forcing the device to
     * re-authenticate before it can use the network again. This does
     * NOT touch the underlying hotspot user account (still enabled,
     * same credentials work again right away) — it only ends the
     * current live connection. $activeSessionId is the active session's
     * own .id from getActiveUsers(), not the hotspot user's .id — these
     * are two different RouterOS record types.
     */
    public function removeActiveSession(string $activeSessionId): array
    {
        return $this->run('remove-active-session', function (Client $client) use ($activeSessionId) {
            $query = (new Query('/ip/hotspot/active/remove'))->equal('.id', $activeSessionId);
            return $client->query($query)->read();
        });
    }

    /**
     * Create a hotspot user. $password should already be a randomly
     * generated hotspot-only secret — never the customer's account password.
     */
    public function createHotspotUser(string $username, string $password, ?string $profile = null): array
    {
        return $this->run('create-hotspot-user', function (Client $client) use ($username, $password, $profile) {
            $query = (new Query('/ip/hotspot/user/add'))
                ->equal('name', $username)
                ->equal('password', $password);

            if ($profile) {
                $query->equal('profile', $profile);
            }

            return $client->query($query)->read();
        }, redact: ['password']);
    }

    public function enableHotspotUser(string $mikrotikUserId): array
    {
        return $this->run('enable-hotspot-user', function (Client $client) use ($mikrotikUserId) {
            $query = (new Query('/ip/hotspot/user/enable'))->equal('.id', $mikrotikUserId);
            return $client->query($query)->read();
        });
    }

    public function disableHotspotUser(string $mikrotikUserId): array
    {
        return $this->run('disable-hotspot-user', function (Client $client) use ($mikrotikUserId) {
            $query = (new Query('/ip/hotspot/user/disable'))->equal('.id', $mikrotikUserId);
            return $client->query($query)->read();
        });
    }

    public function removeHotspotUser(string $mikrotikUserId): array
    {
        return $this->run('remove-hotspot-user', function (Client $client) use ($mikrotikUserId) {
            $query = (new Query('/ip/hotspot/user/remove'))->equal('.id', $mikrotikUserId);
            return $client->query($query)->read();
        });
    }

    public function changeUserProfile(string $mikrotikUserId, string $profile): array
    {
        return $this->run('change-user-profile', function (Client $client) use ($mikrotikUserId, $profile) {
            $query = (new Query('/ip/hotspot/user/set'))
                ->equal('.id', $mikrotikUserId)
                ->equal('profile', $profile);
            return $client->query($query)->read();
        });
    }

    public function disconnectActiveUser(string $activeSessionId): array
    {
        return $this->run('disconnect-active-user', function (Client $client) use ($activeSessionId) {
            $query = (new Query('/ip/hotspot/active/remove'))->equal('.id', $activeSessionId);
            return $client->query($query)->read();
        });
    }

    /**
     * Allow the app's domain through the hotspot's walled garden — an
     * unauthenticated device is blocked from reaching anything else by
     * default, including our own guest checkout page, until this is
     * added.
     */
    public function addWalledGardenEntry(string $host): array
    {
        return $this->runProvisioning('add-walled-garden-entry', function (Client $client) use ($host) {
            $query = (new Query('/ip/hotspot/walled-garden/add'))
                ->equal('dst-host', $host)
                ->equal('action', 'allow');
            return $client->query($query)->read();
        });
    }

    /**
     * Tell the router to download our per-router login page and save it
     * as its own hotspot login.html — from that point on, every guest
     * hitting this hotspot sees our page instead of MikroTik's default.
     * The router does the actual fetch itself; we never push a file
     * over the API connection directly.
     */
    public function fetchLoginPage(string $url): array
    {
        return $this->runProvisioning('fetch-login-page', function (Client $client) use ($url) {
            $query = (new Query('/tool/fetch'))
                ->equal('url', $url)
                ->equal('dst-path', 'hotspot/login.html')
                ->equal('mode', 'https');
            return $client->query($query)->read();
        });
    }

    /**
     * Same reasoning as run(), but connects with the separate
     * provisioning credential and fails clearly if that credential was
     * never set up for this router (rather than a confusing decrypt()
     * error on a null value).
     */
    protected function runProvisioning(string $action, callable $callback): array
    {
        $requestPayload = ['router_id' => $this->router->id, 'action' => $action];

        if ($this->router->isManual()) {
            $error = 'This router is set to Manual mode — no live connection is available.';
            $this->logAction($action, $requestPayload, null, 'failed', $error);
            return ['success' => false, 'data' => null, 'error' => $error];
        }

        if (! $this->router->provisioning_api_username || ! $this->router->provisioning_api_password) {
            $error = 'No provisioning credentials set up for this router. Add them under Edit Router before using Set Up Guest Portal.';
            $this->logAction($action, $requestPayload, null, 'failed', $error);
            return ['success' => false, 'data' => null, 'error' => $error];
        }

        try {
            $client = $this->connectProvisioning();
            $result = $callback($client);

            $this->logAction($action, $requestPayload, $result, 'success', null);

            return ['success' => true, 'data' => $result, 'error' => null];
        } catch (Throwable $e) {
            $this->logAction($action, $requestPayload, null, 'failed', $e->getMessage());

            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Wrap a RouterOS call: connect, run, log, and normalize the response.
     * $redact lists request-array keys whose values should be masked before
     * being written to mikrotik_logs (see review note on log redaction).
     */
    protected function run(string $action, callable $callback, array $redact = []): array
    {
        $requestPayload = ['router_id' => $this->router->id, 'action' => $action];

        // Manual-mode routers have no live VPS/WireGuard path at all —
        // short-circuit here rather than attempting a connection that
        // was never going to succeed and just wastes the connection
        // timeout. Every existing caller already checks `success` before
        // touching anything, since a real offline router was always a
        // possibility — so this one guard is enough to make the whole
        // app degrade correctly for manual routers without touching
        // every call site individually.
        if ($this->router->isManual()) {
            $error = 'This router is set to Manual mode — no live connection is available. Complete this action directly on the router.';
            $this->logAction($action, $requestPayload, null, 'failed', $error, $redact);

            return ['success' => false, 'data' => null, 'error' => $error];
        }

        try {
            $client = $this->connect();
            $result = $callback($client);

            $this->logAction($action, $requestPayload, $result, 'success', null, $redact);

            return ['success' => true, 'data' => $result, 'error' => null];
        } catch (Throwable $e) {
            $this->logAction($action, $requestPayload, null, 'failed', $e->getMessage(), $redact);

            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    protected function logAction(
        string $action,
        array $request,
        mixed $response,
        string $status,
        ?string $errorMessage,
        array $redact = []
    ): void {
        foreach ($redact as $key) {
            if (isset($request[$key])) {
                $request[$key] = '***REDACTED***';
            }
        }

        MikrotikLog::create([
            'router_id'        => $this->router->id,
            'action'           => $action,
            'request_payload'  => json_encode($request),
            'response_payload' => $response ? json_encode($response) : null,
            'status'           => $status,
            'error_message'    => $errorMessage,
        ]);
    }
}
