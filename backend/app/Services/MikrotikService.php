<?php

namespace App\Services;

use App\Models\MikrotikLog;
use App\Models\Router;
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
            'port' => (int) ($this->router->api_port ?: 8729),
            'ssl' => (bool) $this->router->api_ssl,
            'timeout' => (int) env('MIKROTIK_CONNECTION_TIMEOUT', 5),
            'socket_timeout' => (int) env('MIKROTIK_SOCKET_TIMEOUT', 30),
            'throw_timeout_exception' => PHP_VERSION_ID < 80400,
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
            'port' => (int) ($this->router->api_port ?: 8729),
            'ssl' => (bool) $this->router->api_ssl,
            'timeout' => (int) env('MIKROTIK_CONNECTION_TIMEOUT', 5),
            'socket_timeout' => (int) env('MIKROTIK_SOCKET_TIMEOUT', 30),
            'throw_timeout_exception' => PHP_VERSION_ID < 80400,
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
     * Fetch one reduced connection-tracking snapshot for aggregation in
     * Laravel. This is deliberately one query per router, never one query
     * per customer.
     */
    public function getConnectionTrackingSnapshot(): array
    {
        return $this->run(
            'get-connection-tracking-snapshot',
            function (Client $client) {
                $query = (new Query('/ip/firewall/connection/print'))
                    ->equal('.proplist', '.id,protocol,connection-mark,src-address,dst-address,tcp-state,seen-reply,assured,timeout,orig-bytes,repl-bytes,orig-rate,repl-rate');

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
     * Verify that a hotspot account exists with the configuration required
     * by the application. A successful API transport is not sufficient proof
     * that RouterOS accepted the preceding add/set command.
     */
    public function verifyHotspotUser(string $username, string $expectedProfile): array
    {
        return $this->run(
            'verify-hotspot-user',
            function (Client $client) use ($username, $expectedProfile) {
                $users = $client->query(
                    (new Query('/ip/hotspot/user/print'))->where('name', $username)
                )->read();

                $user = collect($users)->first(
                    fn (array $candidate) => ($candidate['name'] ?? null) === $username
                );

                if (! $user || empty($user['.id'])) {
                    throw new \RuntimeException("MikroTik hotspot user {$username} was not found after provisioning.");
                }

                if (($user['profile'] ?? null) !== $expectedProfile) {
                    throw new \RuntimeException("MikroTik hotspot user {$username} was assigned an unexpected profile.");
                }

                if (filter_var($user['disabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    throw new \RuntimeException("MikroTik hotspot user {$username} is disabled after provisioning.");
                }

                return [
                    'mikrotik_user_id' => $user['.id'],
                    'username' => $user['name'],
                    'profile' => $user['profile'],
                    'disabled' => false,
                ];
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

    public function getHotspotHosts(): array
    {
        return $this->run('get-hotspot-hosts', fn (Client $client) => $client->query(new Query('/ip/hotspot/host/print'))->read()
        );
    }

    public function getHotspotIpBindings(): array
    {
        return $this->run('get-hotspot-ip-bindings', fn (Client $client) => $client->query(new Query('/ip/hotspot/ip-binding/print'))->read()
        );
    }

    public function getInterfaces(): array
    {
        return $this->run('get-interfaces', fn (Client $client) => $client->query(new Query('/interface/print'))->read()
        );
    }

    public function getInterfaceTraffic(): array
    {
        return $this->run('get-interface-traffic', function (Client $client) {
            $interfaces = $client->query(new Query('/interface/print'))->read();
            $traffic = [];
            foreach ($interfaces as $interface) {
                if (empty($interface['name']) || ($interface['running'] ?? 'false') !== 'true') {
                    continue;
                }
                $sample = $client->query((new Query('/interface/monitor-traffic'))
                    ->equal('interface', $interface['name'])->equal('once', ''))->read();
                if (isset($sample[0])) {
                    $traffic[] = array_merge(['name' => $interface['name']], $sample[0]);
                }
            }

            return $traffic;
        });
    }

    public function updateHotspotUser(string $id, array $values): array
    {
        return $this->run('update-hotspot-user', function (Client $client) use ($id, $values) {
            $query = (new Query('/ip/hotspot/user/set'))->equal('.id', $id);
            foreach ($values as $name => $value) {
                if ($value !== null) {
                    $query->equal($name, (string) $value);
                }
            }

            return $client->query($query)->read();
        });
    }

    /** Disconnect a user, clear saved logins, then replace the password by exact username. */
    public function resetHotspotUserPassword(string $username, string $password): array
    {
        return $this->run('reset-hotspot-user-password', function (Client $client) use ($username, $password) {
            $users = $this->checkedQuery($client,
                (new Query('/ip/hotspot/user/print'))->where('name', $username)
            );
            $user = collect($users)->first(
                fn (array $candidate) => ($candidate['name'] ?? null) === $username && ! empty($candidate['.id'])
            );
            if (! $user) {
                throw new \RuntimeException("MikroTik hotspot user {$username} was not found.");
            }

            $sessions = $this->checkedQuery($client,
                (new Query('/ip/hotspot/active/print'))->where('user', $username)
            );
            $disconnected = 0;
            foreach ($sessions as $session) {
                if (! empty($session['.id']) && ($session['user'] ?? null) === $username) {
                    $this->checkedQuery($client,
                        (new Query('/ip/hotspot/active/remove'))->equal('.id', $session['.id'])
                    );
                    $disconnected++;
                }
            }
            $removedCookies = $this->removeCookiesForUser($client, $username);
            $this->checkedQuery($client,
                (new Query('/ip/hotspot/user/set'))
                    ->equal('.id', $user['.id'])
                    ->equal('password', $password)
            );

            return [
                'mikrotik_user_id' => $user['.id'],
                'disconnected_sessions' => $disconnected,
                'removed_cookies' => $removedCookies,
            ];
        });
    }

    /** Apply a per-user rate and reconnect active sessions so it takes effect now. */
    public function setUserRateAndReconnect(string $id, string $username, string $rateLimit): array
    {
        return $this->run('set-user-fup-rate', function (Client $client) use ($id, $username, $rateLimit) {
            $client->query(
                (new Query('/ip/hotspot/user/set'))
                    ->equal('.id', $id)
                    ->equal('rate-limit', $rateLimit)
            )->read();

            $sessions = $client->query(
                (new Query('/ip/hotspot/active/print'))->where('user', $username)
            )->read();
            foreach ($sessions as $session) {
                if (! empty($session['.id'])) {
                    $client->query(
                        (new Query('/ip/hotspot/active/remove'))->equal('.id', $session['.id'])
                    )->read();
                }
            }

            return ['username' => $username, 'rate_limit' => $rateLimit, 'reconnected' => count($sessions)];
        });
    }

    /** Reset prior-cycle counters and configure RouterOS's native hard cap. */
    public function configureUserUsagePolicy(string $id, ?string $rateLimit, ?int $limitBytesTotal): array
    {
        return $this->run('configure-user-usage-policy', function (Client $client) use ($id, $limitBytesTotal) {
            $client->query(
                (new Query('/ip/hotspot/user/reset-counters'))->equal('.id', $id)
            )->read();

            // RouterOS HotSpot users support limit-bytes-total, but
            // rate-limit belongs to /ip hotspot user profile. Sending
            // rate-limit here makes RouterOS reject the entire command,
            // leaving the user uncapped. The base rate is already applied
            // by ensureHotspotUserProfile().
            $query = (new Query('/ip/hotspot/user/set'))
                ->equal('.id', $id)
                ->equal('limit-bytes-total', (string) ($limitBytesTotal ?? 0));

            return $client->query($query)->read();
        });
    }

    public function createHotspotIpBinding(array $values): array
    {
        return $this->run('create-hotspot-ip-binding', function (Client $client) use ($values) {
            $query = new Query('/ip/hotspot/ip-binding/add');
            foreach ($values as $name => $value) {
                if ($value !== null && $value !== '') {
                    $query->equal($name, (string) $value);
                }
            }

            return $client->query($query)->read();
        });
    }

    public function updateHotspotIpBinding(string $id, array $values): array
    {
        return $this->run('update-hotspot-ip-binding', function (Client $client) use ($id, $values) {
            $query = (new Query('/ip/hotspot/ip-binding/set'))->equal('.id', $id);
            foreach ($values as $name => $value) {
                if ($value !== null) {
                    $query->equal($name, (string) $value);
                }
            }

            return $client->query($query)->read();
        });
    }

    public function removeHotspotIpBinding(string $id): array
    {
        return $this->run('remove-hotspot-ip-binding', fn (Client $client) => $client->query((new Query('/ip/hotspot/ip-binding/remove'))->equal('.id', $id))->read()
        );
    }

    public function getHotspotProfiles(): array
    {
        return $this->run('get-hotspot-profiles', fn (Client $client) => $client->query(new Query('/ip/hotspot/user/profile/print'))->read());
    }

    public function updateHotspotProfile(string $id, array $values): array
    {
        return $this->setValues('update-hotspot-profile', '/ip/hotspot/user/profile/set', $id, $values);
    }

    public function removeHotspotProfile(string $id): array
    {
        return $this->removeById('remove-hotspot-profile', '/ip/hotspot/user/profile/remove', $id);
    }

    public function getDhcpServers(): array
    {
        return $this->run('get-dhcp-servers', fn (Client $client) => $client->query(new Query('/ip/dhcp-server/print'))->read());
    }

    public function getDhcpLeases(): array
    {
        return $this->run('get-dhcp-leases', fn (Client $client) => $client->query(new Query('/ip/dhcp-server/lease/print'))->read());
    }

    public function makeDhcpLeaseStatic(string $id): array
    {
        return $this->run('make-dhcp-lease-static', fn (Client $client) => $client->query((new Query('/ip/dhcp-server/lease/make-static'))->equal('.id', $id))->read());
    }

    public function removeDhcpLease(string $id): array
    {
        return $this->removeById('remove-dhcp-lease', '/ip/dhcp-server/lease/remove', $id);
    }

    public function updateDhcpLease(string $id, array $values): array
    {
        return $this->setValues('update-dhcp-lease', '/ip/dhcp-server/lease/set', $id, $values);
    }

    public function getSimpleQueues(): array
    {
        return $this->run('get-simple-queues', fn (Client $client) => $client->query(new Query('/queue/simple/print'))->read());
    }

    public function createSimpleQueue(array $values): array
    {
        return $this->addValues('create-simple-queue', '/queue/simple/add', $values);
    }

    public function updateSimpleQueue(string $id, array $values): array
    {
        return $this->setValues('update-simple-queue', '/queue/simple/set', $id, $values);
    }

    public function removeSimpleQueue(string $id): array
    {
        return $this->removeById('remove-simple-queue', '/queue/simple/remove', $id);
    }

    public function getRouterLogs(): array
    {
        return $this->run('get-router-logs', function (Client $client) {
            $query = (new Query('/log/print'))
                ->equal('.proplist', '.id,time,topics,message');

            return array_slice($client->query($query)->read(), -100);
        });
    }

    public function getHotspotServers(): array
    {
        return $this->printPath('get-hotspot-servers', '/ip/hotspot/print');
    }

    public function createHotspotServer(array $values): array
    {
        return $this->addValues('create-hotspot-server', '/ip/hotspot/add', $values);
    }

    public function updateHotspotServer(string $id, array $values): array
    {
        return $this->setValues('update-hotspot-server', '/ip/hotspot/set', $id, $values);
    }

    public function removeHotspotServer(string $id): array
    {
        return $this->removeById('remove-hotspot-server', '/ip/hotspot/remove', $id);
    }

    public function getIpPools(): array
    {
        return $this->printPath('get-ip-pools', '/ip/pool/print');
    }

    public function createIpPool(array $values): array
    {
        return $this->addValues('create-ip-pool', '/ip/pool/add', $values);
    }

    public function updateIpPool(string $id, array $values): array
    {
        return $this->setValues('update-ip-pool', '/ip/pool/set', $id, $values);
    }

    public function removeIpPool(string $id): array
    {
        return $this->removeById('remove-ip-pool', '/ip/pool/remove', $id);
    }

    public function getDhcpNetworks(): array
    {
        return $this->printPath('get-dhcp-networks', '/ip/dhcp-server/network/print');
    }

    public function createDhcpNetwork(array $values): array
    {
        return $this->addValues('create-dhcp-network', '/ip/dhcp-server/network/add', $values);
    }

    public function updateDhcpNetwork(string $id, array $values): array
    {
        return $this->setValues('update-dhcp-network', '/ip/dhcp-server/network/set', $id, $values);
    }

    public function removeDhcpNetwork(string $id): array
    {
        return $this->removeById('remove-dhcp-network', '/ip/dhcp-server/network/remove', $id);
    }

    public function getHotspotCookies(): array
    {
        return $this->printPath('get-hotspot-cookies', '/ip/hotspot/cookie/print');
    }

    public function removeHotspotCookie(string $id): array
    {
        return $this->removeById('remove-hotspot-cookie', '/ip/hotspot/cookie/remove', $id);
    }

    public function getArpEntries(): array
    {
        return $this->printPath('get-arp-entries', '/ip/arp/print');
    }

    public function getIpAddresses(): array
    {
        return $this->printPath('get-ip-addresses', '/ip/address/print');
    }

    public function getDnsCache(): array
    {
        return $this->printPath('get-dns-cache', '/ip/dns/cache/print');
    }

    public function getDnsSettings(): array
    {
        return $this->printPath('get-dns-settings', '/ip/dns/print');
    }

    public function getSystemIdentity(): array
    {
        return $this->printPath('get-system-identity', '/system/identity/print');
    }

    public function getSystemClock(): array
    {
        return $this->printPath('get-system-clock', '/system/clock/print');
    }

    public function getSystemHealth(): array
    {
        return $this->printPath('get-system-health', '/system/health/print');
    }

    public function getNtpClient(): array
    {
        return $this->printPath('get-ntp-client', '/system/ntp/client/print');
    }

    public function getPackageUpdateStatus(): array
    {
        return $this->printPath('get-package-update-status', '/system/package/update/print');
    }

    public function getWirelessClients(): array
    {
        return $this->printPath('get-wireless-clients', '/interface/wireless/registration-table/print');
    }

    public function ping(string $address): array
    {
        return $this->run('ping', fn (Client $client) => $client->query((new Query('/ping'))->equal('address', $address)->equal('count', '4'))->read());
    }

    public function traceroute(string $address): array
    {
        return $this->run('traceroute', fn (Client $client) => $client->query((new Query('/tool/traceroute'))->equal('address', $address)->equal('count', '1'))->read());
    }

    /** Execute a controller-approved, read-only RouterOS command. */
    public function executeReadOnlyTerminal(string $command): array
    {
        $parts = preg_split('/\s+/', trim($command));
        $menu = array_shift($parts);
        $verb = array_shift($parts);
        $path = $verb === 'print' ? rtrim($menu, '/').'/print' : $menu;
        if ($verb !== 'print' && $verb !== null) {
            array_unshift($parts, $verb);
        }

        return $this->runProvisioning('terminal-read-only', function (Client $client) use ($path, $parts) {
            $query = new Query($path);
            foreach ($parts as $part) {
                [$name, $value] = array_pad(explode('=', $part, 2), 2, null);
                if ($value !== null) {
                    $query->equal($name, $value);
                }
            }

            return $client->query($query)->read();
        });
    }

    /** Execute one RouterOS API command; authorization is enforced by the controller. */
    public function executeTerminal(string $command): array
    {
        $parts = array_values(array_filter(str_getcsv(trim($command), ' ', '"', '\\'), fn ($part) => $part !== ''));
        $first = array_shift($parts);
        if (! $first || ! str_starts_with($first, '/')) {
            return ['success' => false, 'error' => 'RouterOS commands must start with /.'];
        }

        $pathParts = [trim($first, '/')];
        $arguments = [];
        $where = false;
        foreach ($parts as $part) {
            if (strtolower($part) === 'where') {
                $where = true;

                continue;
            }
            if (! str_contains($part, '=') && ! str_starts_with($part, '?') && empty($arguments) && ! $where) {
                $pathParts[] = trim($part, '/');

                continue;
            }
            $arguments[] = [$part, $where];
        }

        $path = '/'.implode('/', $pathParts);

        return $this->runProvisioning('terminal-command', function (Client $client) use ($path, $arguments) {
            $query = new Query($path);
            foreach ($arguments as [$part, $isWhere]) {
                $part = ltrim($part, '?');
                [$name, $value] = array_pad(explode('=', $part, 2), 2, null);
                if ($value !== null) {
                    $query = $isWhere ? $query->where($name, $value) : $query->equal($name, $value);
                }
            }

            return $client->query($query)->read();
        });
    }

    public function getBridges(): array
    {
        return $this->provisioningPrint('get-bridges', '/interface/bridge/print');
    }

    public function createBridge(array $values): array
    {
        return $this->provisioningAdd('create-bridge', '/interface/bridge/add', $values);
    }

    public function updateBridge(string $id, array $values): array
    {
        return $this->provisioningSet('update-bridge', '/interface/bridge/set', $id, $values);
    }

    public function removeBridge(string $id): array
    {
        return $this->provisioningRemove('remove-bridge', '/interface/bridge/remove', $id);
    }

    public function getBridgePorts(): array
    {
        return $this->runProvisioning('get-bridge-ports', function (Client $client) {
            $query = (new Query('/interface/bridge/port/print'))
                ->equal('.proplist', '.id,interface,bridge,pvid,path-cost,priority,edge,hw,hw-offload,role,dynamic,disabled');

            return $client->query($query)->read();
        });
    }

    public function createBridgePort(array $values): array
    {
        return $this->provisioningAdd('create-bridge-port', '/interface/bridge/port/add', $values);
    }

    public function updateBridgePort(string $id, array $values): array
    {
        return $this->provisioningSet('update-bridge-port', '/interface/bridge/port/set', $id, $values);
    }

    public function removeBridgePort(string $id): array
    {
        return $this->provisioningRemove('remove-bridge-port', '/interface/bridge/port/remove', $id);
    }

    public function getBridgeHosts(): array
    {
        return $this->provisioningPrint('get-bridge-hosts', '/interface/bridge/host/print');
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
            function (Client $client) use ($username) {
                // IDs may be stale after a router restore. Resolve ownership by name.
                $users = $this->checkedQuery($client,
                    (new Query('/ip/hotspot/user/print'))->where('name', $username)
                );
                $userId = collect($users)->first(fn ($user) => ($user['name'] ?? null) === $username)['.id'] ?? null;

                if ($userId) {
                    $this->checkedQuery($client,
                        (new Query('/ip/hotspot/user/disable'))->equal('.id', $userId)
                    );
                }

                $sessions = $this->checkedQuery($client,
                    (new Query('/ip/hotspot/active/print'))->where('user', $username)
                );

                $disconnected = 0;
                foreach ($sessions as $session) {
                    if (empty($session['.id']) || ($session['user'] ?? null) !== $username) {
                        continue;
                    }

                    $this->checkedQuery($client,
                        (new Query('/ip/hotspot/active/remove'))->equal('.id', $session['.id'])
                    );
                    $disconnected++;
                }

                $removedCookies = $this->removeCookiesForUser($client, $username);

                return [
                    'username' => $username,
                    'disabled' => (bool) $userId,
                    'disconnected_sessions' => $disconnected,
                    'removed_cookies' => $removedCookies,
                ];
            }
        );
    }

    /** Remove only one customer's active sessions and saved login cookies. */
    public function disconnectAndClearHotspotCookies(string $username, ?string $macAddress = null): array
    {
        return $this->run(
            'disconnect-and-clear-hotspot-cookies',
            function (Client $client) use ($username, $macAddress) {
                $sessions = $this->checkedQuery($client,
                    (new Query('/ip/hotspot/active/print'))->where('user', $username)
                );

                $disconnected = 0;
                foreach ($sessions as $session) {
                    if (empty($session['.id']) || ($session['user'] ?? null) !== $username) {
                        continue;
                    }
                    $this->checkedQuery($client, (new Query('/ip/hotspot/active/remove'))->equal('.id', $session['.id']));
                    $disconnected++;
                }

                return [
                    'username' => $username,
                    'disconnected_sessions' => $disconnected,
                    'removed_cookies' => $this->removeCookiesForUser($client, $username, $macAddress),
                ];
            }
        );
    }

    protected function removeCookiesForUser(Client $client, string $username, ?string $macAddress = null): int
    {
        $cookies = $this->checkedQuery($client,
            (new Query('/ip/hotspot/cookie/print'))->where('user', $username)
        );
        $normalizedMac = $macAddress ? strtoupper(str_replace('-', ':', $macAddress)) : null;
        $removed = 0;

        foreach ($cookies as $cookie) {
            if (empty($cookie['.id']) || ($cookie['user'] ?? null) !== $username) {
                continue;
            }
            $cookieMac = strtoupper(str_replace('-', ':', (string) ($cookie['mac-address'] ?? '')));
            if ($normalizedMac && $cookieMac && $cookieMac !== $normalizedMac) {
                continue;
            }
            $this->checkedQuery($client, (new Query('/ip/hotspot/cookie/remove'))->equal('.id', $cookie['.id']));
            $removed++;
        }

        return $removed;
    }

    protected function checkedQuery(Client $client, Query $query): array
    {
        $response = $client->query($query)->read();
        if ($error = $this->routerOsCommandError($response)) {
            throw new \RuntimeException($error);
        }

        return $response;
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
    ): array {
        return $this->run(
            'ensure-hotspot-user-profile',
            function (Client $client) use ($name, $rateLimit, $addressPool, $sharedUsers) {

                $checkQuery = (new Query('/ip/hotspot/user/profile/print'))
                    ->where('name', $name);

                $existing = $client->query($checkQuery)->read();

                if (! empty($existing)) {
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

                if (! empty($existing)) {
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

    public function getAddressLists(): array
    {
        return $this->runProvisioning('get-address-lists', fn (Client $client) => $client->query(new Query('/ip/firewall/address-list/print'))->read()
        );
    }

    public function getWalledGarden(): array
    {
        return $this->runProvisioning('get-walled-garden', fn (Client $client) => $client->query(new Query('/ip/hotspot/walled-garden/print'))->read());
    }

    public function createWalledGardenEntry(array $values): array
    {
        return $this->runProvisioning('create-walled-garden-entry', function (Client $client) use ($values) {
            $query = new Query('/ip/hotspot/walled-garden/add');
            foreach ($values as $name => $value) {
                if ($value !== null && $value !== '') {
                    $query->equal($name, (string) $value);
                }
            }

            return $client->query($query)->read();
        });
    }

    public function updateWalledGardenEntry(string $id, array $values): array
    {
        return $this->runProvisioning('update-walled-garden-entry', function (Client $client) use ($id, $values) {
            $query = (new Query('/ip/hotspot/walled-garden/set'))->equal('.id', $id);
            foreach ($values as $name => $value) {
                if ($value !== null) {
                    $query->equal($name, (string) $value);
                }
            }

            return $client->query($query)->read();
        });
    }

    public function removeWalledGardenEntry(string $id): array
    {
        return $this->runProvisioning('remove-walled-garden-entry', fn (Client $client) => $client->query((new Query('/ip/hotspot/walled-garden/remove'))->equal('.id', $id))->read());
    }

    public function createAddressListEntry(array $values): array
    {
        return $this->runProvisioning('create-address-list-entry', function (Client $client) use ($values) {
            $query = new Query('/ip/firewall/address-list/add');
            foreach ($values as $name => $value) {
                if ($value !== null && $value !== '') {
                    $query->equal($name, (string) $value);
                }
            }

            return $client->query($query)->read();
        });
    }

    public function removeAddressListEntry(string $id): array
    {
        return $this->runProvisioning('remove-address-list-entry', fn (Client $client) => $client->query((new Query('/ip/firewall/address-list/remove'))->equal('.id', $id))->read()
        );
    }

    public function updateAddressListEntry(string $id, array $values): array
    {
        return $this->runProvisioning('update-address-list-entry', function (Client $client) use ($id, $values) {
            $query = (new Query('/ip/firewall/address-list/set'))->equal('.id', $id);
            foreach ($values as $name => $value) {
                if ($value !== null) {
                    $query->equal($name, (string) $value);
                }
            }

            return $client->query($query)->read();
        });
    }

    public function getBackupFiles(): array
    {
        return $this->runProvisioning('get-backup-files', fn (Client $client) => $client->query((new Query('/file/print'))->where('type', 'backup'))->read()
        );
    }

    public function createBackup(string $name, string $password): array
    {
        return $this->runProvisioning('create-backup', fn (Client $client) => $client->query((new Query('/system/backup/save'))
            ->equal('name', $name)->equal('password', $password)->equal('dont-encrypt', 'no'))->read()
        );
    }

    public function removeBackupFile(string $id): array
    {
        return $this->runProvisioning('remove-backup-file', fn (Client $client) => $client->query((new Query('/file/remove'))->equal('.id', $id))->read()
        );
    }

    private function addValues(string $action, string $path, array $values): array
    {
        return $this->run($action, function (Client $client) use ($path, $values) {
            $query = new Query($path);
            foreach ($values as $name => $value) {
                if ($value !== null && $value !== '') {
                    $query->equal($name, (string) $value);
                }
            }

            return $client->query($query)->read();
        });
    }

    private function printPath(string $action, string $path): array
    {
        return $this->run($action, fn (Client $client) => $client->query(new Query($path))->read());
    }

    private function provisioningPrint(string $action, string $path): array
    {
        return $this->runProvisioning($action, fn (Client $client) => $client->query(new Query($path))->read());
    }

    private function provisioningAdd(string $action, string $path, array $values): array
    {
        return $this->runProvisioning($action, function (Client $client) use ($path, $values) {
            $query = new Query($path);
            foreach ($values as $name => $value) {
                if ($value !== null && $value !== '') {
                    $query->equal($name, (string) $value);
                }
            }

            return $client->query($query)->read();
        });
    }

    private function provisioningSet(string $action, string $path, string $id, array $values): array
    {
        return $this->runProvisioning($action, function (Client $client) use ($path, $id, $values) {
            $query = (new Query($path))->equal('.id', $id);
            foreach ($values as $name => $value) {
                if ($value !== null) {
                    $query->equal($name, (string) $value);
                }
            }

            return $client->query($query)->read();
        });
    }

    private function provisioningRemove(string $action, string $path, string $id): array
    {
        return $this->runProvisioning($action, fn (Client $client) => $client->query((new Query($path))->equal('.id', $id))->read());
    }

    private function setValues(string $action, string $path, string $id, array $values): array
    {
        return $this->run($action, function (Client $client) use ($path, $id, $values) {
            $query = (new Query($path))->equal('.id', $id);
            foreach ($values as $name => $value) {
                if ($value !== null) {
                    $query->equal($name, (string) $value);
                }
            }

            return $client->query($query)->read();
        });
    }

    private function removeById(string $action, string $path, string $id): array
    {
        return $this->run($action, fn (Client $client) => $client->query((new Query($path))->equal('.id', $id))->read());
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

            if ($error = $this->routerOsCommandError($result)) {
                throw new \RuntimeException($error);
            }

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

            if ($error = $this->routerOsCommandError($result)) {
                throw new \RuntimeException($error);
            }

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
     * RouterOS can return a syntactically valid response containing a trap or
     * an `after.message` command error. The client library does not always
     * throw for these responses, so normalize them into operation failures.
     */
    protected function routerOsCommandError(mixed $result): ?string
    {
        if (! is_array($result)) {
            return null;
        }

        foreach ($result as $key => $value) {
            if (in_array((string) $key, ['!trap', '!fatal'], true)) {
                return is_array($value)
                    ? (string) ($value['message'] ?? json_encode($value))
                    : (string) $value;
            }

            if ($key === 'after' && is_array($value) && ! empty($value['message'])) {
                return (string) $value['message'];
            }

            if (is_array($value) && ($error = $this->routerOsCommandError($value))) {
                return $error;
            }
        }

        return null;
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
