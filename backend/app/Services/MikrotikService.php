<?php

namespace App\Services;

use App\Models\Router;
use App\Models\MikrotikLog;
use RouterOS\Client;
use RouterOS\Query;
use Throwable;

class MikrotikService
{
    protected Router $router;

    public function __construct(Router $router)
    {
        $this->router = $router;
    }

    /**
     * Connect to MikroTik using the normal API credentials.
     */
    protected function connect(): Client
    {
        return new Client([
            'host' => $this->router->wireguard_ip,
            'user' => $this->router->api_username,
            'pass' => $this->router->api_password,
            'port' => (int) ($this->router->api_port ?: 8728),
            'ssl' => (bool) $this->router->api_ssl,
            'timeout' => (int) env('MIKROTIK_CONNECTION_TIMEOUT', 5),
        ]);
    }

    /**
     * Connect using provisioning credentials.
     */
    protected function connectProvisioning(): Client
    {
        return new Client([
            'host' => $this->router->wireguard_ip,
            'user' => $this->router->provisioning_api_username,
            'pass' => $this->router->provisioning_api_password,
            'port' => (int) ($this->router->api_port ?: 8728),
            'ssl' => (bool) $this->router->api_ssl,
            'timeout' => (int) env('MIKROTIK_CONNECTION_TIMEOUT', 5),
        ]);
    }

    /**
     * Test connection to MikroTik.
     */
    public function testConnection(): array
    {
        return $this->run(
            'test-connection',
            function (Client $client) {
                $query = new Query('/system/resource/print');

                return $client->query($query)->read();
            }
        );
    }

    /**
     * Get MikroTik system resources.
     */
    public function getSystemResource(): array
    {
        return $this->run(
            'get-system-resource',
            function (Client $client) {
                $query = new Query('/system/resource/print');

                return $client->query($query)->read();
            }
        );
    }

    /**
     * Get all hotspot users.
     */
    public function getHotspotUsers(): array
    {
        return $this->run(
            'get-hotspot-users',
            function (Client $client) {
                $query = new Query('/ip/hotspot/user/print');

                return $client->query($query)->read();
            }
        );
    }

    /**
     * Get active hotspot users.
     */
    public function getActiveUsers(): array
    {
        return $this->run(
            'get-active-users',
            function (Client $client) {
                $query = new Query('/ip/hotspot/active/print');

                return $client->query($query)->read();
            }
        );
    }

    /**
     * Create a new hotspot user.
     */
    public function createHotspotUser(
        string $username,
        string $password,
        ?string $profile = null
    ): array {
        return $this->run(
            'create-hotspot-user',
            function (Client $client) use ($username, $password, $profile) {

                $query = (new Query('/ip/hotspot/user/add'))
                    ->equal('name', $username)
                    ->equal('password', $password);

                if ($profile) {
                    $query->equal('profile', $profile);
                }

                return $client->query($query)->read();
            }
        );
    }

    /**
     * Enable a hotspot user.
     */
    public function enableHotspotUser(string $mikrotikUserId): array
    {
        return $this->run(
            'enable-hotspot-user',
            function (Client $client) use ($mikrotikUserId) {

                $query = (new Query('/ip/hotspot/user/enable'))
                    ->equal('.id', $mikrotikUserId);

                return $client->query($query)->read();
            }
        );
    }

    /**
     * Disable a hotspot user.
     */
    public function disableHotspotUser(string $mikrotikUserId): array
    {
        return $this->run(
            'disable-hotspot-user',
            function (Client $client) use ($mikrotikUserId) {

                $query = (new Query('/ip/hotspot/user/disable'))
                    ->equal('.id', $mikrotikUserId);

                return $client->query($query)->read();
            }
        );
    }

    /**
     * Recover and enable an existing RouterOS account when its internal ID
     * was not stored locally. RouterOS IDs are implementation details; the
     * username is the stable identifier shared with this application.
     */
    public function enableHotspotUserByUsername(string $username): array
    {
        return $this->run(
            'enable-hotspot-user-by-username',
            function (Client $client) use ($username) {
                $users = $client->query(
                    (new Query('/ip/hotspot/user/print'))->where('name', $username)
                )->read();

                $userId = $users[0]['.id'] ?? null;
                if (! $userId) {
                    throw new \RuntimeException("MikroTik hotspot user {$username} was not found.");
                }

                $client->query(
                    (new Query('/ip/hotspot/user/enable'))->equal('.id', $userId)
                )->read();

                return ['mikrotik_user_id' => $userId, 'username' => $username];
            }
        );
    }

    /**
     * Disable a hotspot account and immediately terminate any live sessions.
     * Disabling the account prevents the next login, but RouterOS can leave an
     * already-authenticated device online until its session ends. Looking the
     * account up by username also supports guests, which deliberately have no
     * HotspotUser database row or stored RouterOS ID.
     */
    public function disableAndDisconnectHotspotUser(string $username, ?string $mikrotikUserId = null): array
    {
        return $this->run(
            'disable-and-disconnect-hotspot-user',
            function (Client $client) use ($username, $mikrotikUserId) {
                $userId = $mikrotikUserId;

                if (! $userId) {
                    $users = $client->query(
                        (new Query('/ip/hotspot/user/print'))->where('name', $username)
                    )->read();
                    $userId = $users[0]['.id'] ?? null;
                }

                if ($userId) {
                    $client->query(
                        (new Query('/ip/hotspot/user/disable'))->equal('.id', $userId)
                    )->read();
                }

                $sessions = $client->query(
                    (new Query('/ip/hotspot/active/print'))->where('user', $username)
                )->read();

                $disconnected = 0;
                foreach ($sessions as $session) {
                    if (empty($session['.id'])) {
                        continue;
                    }

                    $client->query(
                        (new Query('/ip/hotspot/active/remove'))->equal('.id', $session['.id'])
                    )->read();
                    $disconnected++;
                }

                return [
                    'username' => $username,
                    'disabled' => (bool) $userId,
                    'disconnected_sessions' => $disconnected,
                ];
            }
        );
    }

    /**
     * Remove a hotspot user.
     */
    public function removeHotspotUser(string $mikrotikUserId): array
    {
        return $this->run(
            'remove-hotspot-user',
            function (Client $client) use ($mikrotikUserId) {

                $query = (new Query('/ip/hotspot/user/remove'))
                    ->equal('.id', $mikrotikUserId);

                return $client->query($query)->read();
            }
        );
    }

    /**
     * Create the RouterOS hotspot user profile a package mapping points
     * at, or update its rate-limit if the profile already exists. Package
     * → router mappings (router_package_profiles) used to be DB-only — an
     * admin could map a package to a "profile_name" that had no matching
     * profile on the actual router, so RouterOS fell back to its own
     * default (unlimited) speed for every user created against it. This
     * makes the mapping actually take effect on the router itself.
     */
    public function ensureHotspotUserProfile(
        string $name,
        ?string $rateLimit = null,
        ?string $addressPool = null,
        int $sharedUsers = 1
    ): array
    {
        return $this->run(
            'ensure-hotspot-user-profile',
            function (Client $client) use ($name, $rateLimit, $addressPool, $sharedUsers) {

                $checkQuery = (new Query('/ip/hotspot/user/profile/print'))
                    ->where('name', $name);

                $existing = $client->query($checkQuery)->read();

                if (!empty($existing)) {
                    $query = (new Query('/ip/hotspot/user/profile/set'))
                        ->equal('.id', $existing[0]['.id']);

                    if ($rateLimit) {
                        $query->equal('rate-limit', $rateLimit);
                    }

                    if ($addressPool) {
                        $query->equal('address-pool', $addressPool);
                    }

                    $query->equal('shared-users', (string) $sharedUsers);

                    return $client->query($query)->read();
                }

                $query = (new Query('/ip/hotspot/user/profile/add'))
                    ->equal('name', $name);

                if ($rateLimit) {
                    $query->equal('rate-limit', $rateLimit);
                }

                if ($addressPool) {
                    $query->equal('address-pool', $addressPool);
                }

                $query->equal('shared-users', (string) $sharedUsers);

                return $client->query($query)->read();
            }
        );
    }

    /**
     * Change a hotspot user's profile.
     */
    public function changeUserProfile(
        string $mikrotikUserId,
        string $profile
    ): array {
        return $this->run(
            'change-user-profile',
            function (Client $client) use ($mikrotikUserId, $profile) {

                $query = (new Query('/ip/hotspot/user/set'))
                    ->equal('.id', $mikrotikUserId)
                    ->equal('profile', $profile);

                return $client->query($query)->read();
            }
        );
    }

    /**
     * Disconnect an active hotspot session.
     */
    public function removeActiveSession(string $activeSessionId): array
    {
        return $this->run(
            'remove-active-session',
            function (Client $client) use ($activeSessionId) {

                $query = (new Query('/ip/hotspot/active/remove'))
                    ->equal('.id', $activeSessionId);

                return $client->query($query)->read();
            }
        );
    }

    /**
     * Alias for removing an active session.
     */
    public function disconnectActiveUser(string $activeSessionId): array
    {
        return $this->removeActiveSession($activeSessionId);
    }

    /**
     * Add the application domain to the hotspot walled garden.
     */
    public function addWalledGardenEntry(string $host): array
    {
        return $this->runProvisioning(
            'add-walled-garden-entry',
            function (Client $client) use ($host) {

                // Check whether the entry already exists.
                $checkQuery = (new Query('/ip/hotspot/walled-garden/print'))
                    ->where('dst-host', $host);

                $existing = $client->query($checkQuery)->read();

                if (!empty($existing)) {
                    return [
                        'message' => 'Walled garden entry already exists.',
                        'existing' => true,
                    ];
                }

                // Add the host to the walled garden.
                $query = (new Query('/ip/hotspot/walled-garden/add'))
                    ->equal('dst-host', $host);

                return $client->query($query)->read();
            }
        );
    }

    /**
     * Download the login page to the MikroTik hotspot folder.
     */
    public function fetchLoginPage(string $url): array
    {
        return $this->runProvisioning(
            'fetch-login-page',
            function (Client $client) use ($url) {

                $query = (new Query('/tool/fetch'))
                    ->equal('url', $url)
                    ->equal('dst-path', 'hotspot/login.html');

                return $client->query($query)->read();
            }
        );
    }

    /**
     * Execute an action using provisioning credentials.
     */
    protected function runProvisioning(
        string $action,
        callable $callback
    ): array {
        $requestPayload = [
            'router_id' => $this->router->id,
            'action' => $action,
        ];

        if ($this->router->isManual()) {
            $error = 'This router is set to Manual mode. No live connection is available.';

            $this->logAction(
                $action,
                $requestPayload,
                null,
                'failed',
                $error
            );

            return [
                'success' => false,
                'data' => null,
                'error' => $error,
            ];
        }

        if (
            empty($this->router->provisioning_api_username) ||
            empty($this->router->provisioning_api_password)
        ) {
            $error = 'Provisioning API credentials are not configured for this router.';

            $this->logAction(
                $action,
                $requestPayload,
                null,
                'failed',
                $error
            );

            return [
                'success' => false,
                'data' => null,
                'error' => $error,
            ];
        }

        try {
            $client = $this->connectProvisioning();

            $result = $callback($client);

            $this->logAction(
                $action,
                $requestPayload,
                $result,
                'success',
                null
            );

            return [
                'success' => true,
                'data' => $result,
                'error' => null,
            ];

        } catch (Throwable $e) {

            $this->logAction(
                $action,
                $requestPayload,
                null,
                'failed',
                $e->getMessage()
            );

            return [
                'success' => false,
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Execute an action using the normal MikroTik API credentials.
     */
    protected function run(
        string $action,
        callable $callback
    ): array {
        $requestPayload = [
            'router_id' => $this->router->id,
            'action' => $action,
        ];

        if ($this->router->isManual()) {
            $error = 'This router is set to Manual mode. No live connection is available.';

            $this->logAction(
                $action,
                $requestPayload,
                null,
                'failed',
                $error
            );

            return [
                'success' => false,
                'data' => null,
                'error' => $error,
            ];
        }

        try {
            $client = $this->connect();

            $result = $callback($client);

            $this->logAction(
                $action,
                $requestPayload,
                $result,
                'success',
                null
            );

            return [
                'success' => true,
                'data' => $result,
                'error' => null,
            ];

        } catch (Throwable $e) {

            $this->logAction(
                $action,
                $requestPayload,
                null,
                'failed',
                $e->getMessage()
            );

            return [
                'success' => false,
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Save MikroTik activity to the database.
     */
    protected function logAction(
        string $action,
        array $request,
        mixed $response,
        string $status,
        ?string $errorMessage
    ): void {
        try {
            MikrotikLog::create([
                'router_id' => $this->router->id,
                'action' => $action,
                'request_payload' => json_encode($request),
                'response_payload' => $response !== null
                    ? json_encode($response)
                    : null,
                'status' => $status,
                'error_message' => $errorMessage,
            ]);
        } catch (Throwable $e) {
            // Never allow logging failure to break the MikroTik operation.
        }
    }
}
