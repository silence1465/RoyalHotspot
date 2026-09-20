<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Router;
use App\Services\MikrotikService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MikrotikManagementController extends Controller
{
    private function service(Router $router): MikrotikService
    {
        abort_if($router->isManual(), 422, 'This router is in Manual mode.');

        return new MikrotikService($router);
    }

    private function result(array $result)
    {
        return $result['success']
            ? response()->json(['data' => $result['data']])
            : response()->json(['message' => 'The router could not complete this request.'], 502);
    }

    public function overview(Router $router)
    {
        $service = $this->service($router);
        $resource = $service->getSystemResource();
        $sessions = $service->getActiveUsers();
        $users = $service->getHotspotUsers();
        $hosts = $service->getHotspotHosts();
        $interfaces = $service->getInterfaces();
        $traffic = $service->getInterfaceTraffic();
        if (! $resource['success']) {
            return $this->result($resource);
        }

        return response()->json([
            'router' => $router->only(['id', 'name', 'location', 'status', 'wireguard_ip']),
            'resource' => $resource['data'][0] ?? [],
            'counts' => [
                'active_sessions' => count($sessions['data'] ?? []),
                'hotspot_users' => count($users['data'] ?? []),
                'hosts' => count($hosts['data'] ?? []),
                'interfaces' => count($interfaces['data'] ?? []),
            ],
            'interfaces' => $interfaces['success'] ? $interfaces['data'] : [],
            'traffic' => $traffic['success'] ? $traffic['data'] : [],
            'partial' => ! $sessions['success'] || ! $users['success'] || ! $hosts['success'] || ! $interfaces['success'] || ! $traffic['success'],
        ]);
    }

    public function users(Router $router)
    {
        $result = $this->service($router)->getHotspotUsers();
        if ($result['success']) {
            $result['data'] = array_map(function ($user) {
                unset($user['password']);

                return $user;
            }, $result['data']);
        }

        return $this->result($result);
    }

    public function hosts(Router $router)
    {
        return $this->result($this->service($router)->getHotspotHosts());
    }

    public function bindings(Router $router)
    {
        return $this->result($this->service($router)->getHotspotIpBindings());
    }

    public function storeUser(Request $request, Router $router)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'max:128'],
            'profile' => ['nullable', 'string', 'max:128'],
        ]);

        return $this->result($this->service($router)->createHotspotUser($data['name'], $data['password'], $data['profile'] ?? null));
    }

    public function updateUser(Request $request, Router $router, string $userId)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:128'],
            'password' => ['sometimes', 'string', 'max:128'],
            'profile' => ['sometimes', 'string', 'max:128'],
            'disabled' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('disabled', $data)) {
            $data['disabled'] = $data['disabled'] ? 'yes' : 'no';
        }

        return $this->result($this->service($router)->updateHotspotUser($userId, $data));
    }

    public function destroyUser(Router $router, string $userId)
    {
        return $this->result($this->service($router)->removeHotspotUser($userId));
    }

    private function bindingData(Request $request): array
    {
        $data = $request->validate([
            'address' => ['nullable', 'ip'],
            'mac-address' => ['nullable', 'string', 'max:17', 'regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
            'type' => ['required', Rule::in(['regular', 'blocked', 'bypassed'])],
            'comment' => ['nullable', 'string', 'max:255'],
            'disabled' => ['sometimes', 'boolean'],
        ]);
        abort_if(empty($data['address']) && empty($data['mac-address']), 422, 'Provide an IP address or MAC address.');
        if (array_key_exists('disabled', $data)) {
            $data['disabled'] = $data['disabled'] ? 'yes' : 'no';
        }

        return $data;
    }

    public function storeBinding(Request $request, Router $router)
    {
        return $this->result($this->service($router)->createHotspotIpBinding($this->bindingData($request)));
    }

    public function updateBinding(Request $request, Router $router, string $bindingId)
    {
        return $this->result($this->service($router)->updateHotspotIpBinding($bindingId, $this->bindingData($request)));
    }

    public function setBindingStatus(Request $request, Router $router, string $bindingId)
    {
        $data = $request->validate([
            'active' => ['required', 'boolean'],
        ]);
        abort_if(trim($bindingId) === '', 422, 'A binding ID is required.');

        $result = $this->service($router)->updateHotspotIpBinding($bindingId, [
            'disabled' => $data['active'] ? 'no' : 'yes',
        ]);

        ActivityLog::record('mikrotik.ip_binding.status_changed', json_encode([
            'router_id' => $router->id,
            'binding_id' => $bindingId,
            'active' => $data['active'],
            'outcome' => $result['success'] ? 'success' : 'failed',
        ], JSON_UNESCAPED_SLASHES), ['user_id' => $request->user()->id]);

        return $this->result($result);
    }

    public function destroyBinding(Router $router, string $bindingId)
    {
        return $this->result($this->service($router)->removeHotspotIpBinding($bindingId));
    }

    public function profiles(Router $router)
    {
        return $this->result($this->service($router)->getHotspotProfiles());
    }

    public function storeProfile(Request $request, Router $router)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'rate-limit' => ['nullable', 'string', 'max:128'],
            'address-pool' => ['nullable', 'string', 'max:128'],
            'shared-users' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        return $this->result($this->service($router)->ensureHotspotUserProfile(
            $data['name'], $data['rate-limit'] ?? null, $data['address-pool'] ?? null, $data['shared-users'] ?? 1
        ));
    }

    public function updateProfile(Request $request, Router $router, string $profileId)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:128'],
            'rate-limit' => ['sometimes', 'nullable', 'string', 'max:128'],
            'address-pool' => ['sometimes', 'nullable', 'string', 'max:128'],
            'shared-users' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        return $this->result($this->service($router)->updateHotspotProfile($profileId, $data));
    }

    public function destroyProfile(Router $router, string $profileId)
    {
        return $this->result($this->service($router)->removeHotspotProfile($profileId));
    }

    public function dhcpServers(Router $router)
    {
        return $this->result($this->service($router)->getDhcpServers());
    }

    public function dhcpLeases(Router $router)
    {
        return $this->result($this->service($router)->getDhcpLeases());
    }

    public function makeLeaseStatic(Router $router, string $leaseId)
    {
        return $this->result($this->service($router)->makeDhcpLeaseStatic($leaseId));
    }

    public function destroyLease(Router $router, string $leaseId)
    {
        return $this->result($this->service($router)->removeDhcpLease($leaseId));
    }

    public function updateLease(Request $request, Router $router, string $leaseId)
    {
        $data = $request->validate([
            'address' => ['required', 'ip'],
            'mac-address' => ['required', 'string', 'max:17', 'regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
            'server' => ['nullable', 'string', 'max:128'],
            'comment' => ['nullable', 'string', 'max:255'],
            'disabled' => ['sometimes', 'boolean'],
        ]);

        return $this->result($this->service($router)->updateDhcpLease($leaseId, $this->normalizeRouterBooleans($data)));
    }

    public function queues(Router $router)
    {
        return $this->result($this->service($router)->getSimpleQueues());
    }

    private function queueData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'target' => ['required', 'string', 'max:128', 'regex:/^[0-9a-fA-F:.\/,-]+$/'],
            'max-limit' => ['nullable', 'string', 'max:64', 'regex:/^[0-9kKmMgG\/]+$/'],
            'comment' => ['nullable', 'string', 'max:255'],
            'disabled' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('disabled', $data)) {
            $data['disabled'] = $data['disabled'] ? 'yes' : 'no';
        }

        return $data;
    }

    public function storeQueue(Request $request, Router $router)
    {
        return $this->result($this->service($router)->createSimpleQueue($this->queueData($request)));
    }

    public function updateQueue(Request $request, Router $router, string $queueId)
    {
        return $this->result($this->service($router)->updateSimpleQueue($queueId, $this->queueData($request)));
    }

    public function destroyQueue(Router $router, string $queueId)
    {
        return $this->result($this->service($router)->removeSimpleQueue($queueId));
    }

    public function routerLogs(Router $router)
    {
        return $this->result($this->service($router)->getRouterLogs());
    }

    public function addressLists(Router $router)
    {
        $result = $this->service($router)->getAddressLists();
        if ($result['success']) {
            $allowed = config('mikrotik_security.allowed_address_lists');
            $result['data'] = array_values(array_filter($result['data'], fn ($row) => in_array($row['list'] ?? '', $allowed, true)));
        }

        return $this->result($result);
    }

    public function storeAddressList(Request $request, Router $router)
    {
        $data = $request->validate([
            'list' => ['required', Rule::in(config('mikrotik_security.allowed_address_lists'))],
            'address' => ['required', 'string', 'max:128', 'regex:/^[0-9a-fA-F:.\/,-]+$/'],
            'timeout' => ['nullable', 'string', 'max:32', 'regex:/^[0-9wdhms:]+$/'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->result($this->service($router)->createAddressListEntry($data));
    }

    public function destroyAddressList(Router $router, string $entryId)
    {
        $existing = $this->service($router)->getAddressLists();
        if (! $existing['success']) {
            return $this->result($existing);
        }
        $entry = collect($existing['data'])->firstWhere('.id', $entryId);
        abort_unless($entry && in_array($entry['list'] ?? '', config('mikrotik_security.allowed_address_lists'), true), 404);

        return $this->result($this->service($router)->removeAddressListEntry($entryId));
    }

    public function updateAddressList(Request $request, Router $router, string $entryId)
    {
        $existing = $this->service($router)->getAddressLists();
        if (! $existing['success']) {
            return $this->result($existing);
        }
        $entry = collect($existing['data'])->firstWhere('.id', $entryId);
        abort_unless($entry && in_array($entry['list'] ?? '', config('mikrotik_security.allowed_address_lists'), true), 404);
        $data = $request->validate([
            'list' => ['required', Rule::in(config('mikrotik_security.allowed_address_lists'))],
            'address' => ['required', 'string', 'max:128', 'regex:/^[0-9a-fA-F:.\/,-]+$/'],
            'timeout' => ['nullable', 'string', 'max:32', 'regex:/^[0-9wdhms:]+$/'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->result($this->service($router)->updateAddressListEntry($entryId, $data));
    }

    public function backups(Router $router)
    {
        return $this->result($this->service($router)->getBackupFiles());
    }

    public function storeBackup(Router $router)
    {
        $name = 'royal-hotspot-'.$router->id.'-'.now()->format('Ymd-His');
        $password = Str::password(24, symbols: false);
        $result = $this->service($router)->createBackup($name, $password);
        if (! $result['success']) {
            return $this->result($result);
        }

        return response()->json([
            'data' => $result['data'],
            'name' => $name.'.backup',
            'password' => $password,
            'warning' => 'Save this password now. It will not be shown or stored again.',
        ], 201);
    }

    public function destroyBackup(Router $router, string $fileId)
    {
        $files = $this->service($router)->getBackupFiles();
        if (! $files['success']) {
            return $this->result($files);
        }
        abort_unless(collect($files['data'])->contains(fn ($file) => ($file['.id'] ?? null) === $fileId && ($file['type'] ?? null) === 'backup'), 404);

        return $this->result($this->service($router)->removeBackupFile($fileId));
    }

    public function expandedModule(Router $router, string $module)
    {
        $service = $this->service($router);
        $methods = [
            'hotspot-servers' => 'getHotspotServers',
            'walled-garden' => 'getWalledGarden',
            'ip-pools' => 'getIpPools',
            'dhcp-networks' => 'getDhcpNetworks',
            'hotspot-cookies' => 'getHotspotCookies',
            'arp' => 'getArpEntries',
            'ip-addresses' => 'getIpAddresses',
            'dns-cache' => 'getDnsCache',
            'dns-settings' => 'getDnsSettings',
            'wireless-clients' => 'getWirelessClients',
        ];
        abort_unless(isset($methods[$module]), 404);

        return $this->result($service->{$methods[$module]}());
    }

    private function expandedData(Request $request, string $module): array
    {
        return match ($module) {
            'hotspot-servers' => $request->validate([
                'name' => ['required', 'string', 'max:128'],
                'interface' => ['required', 'string', 'max:128'],
                'address-pool' => ['nullable', 'string', 'max:128'],
                'profile' => ['nullable', 'string', 'max:128'],
                'disabled' => ['sometimes', 'boolean'],
            ]),
            'walled-garden' => $request->validate([
                'dst-host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.*-]+$/'],
                'comment' => ['nullable', 'string', 'max:255'],
                'disabled' => ['sometimes', 'boolean'],
            ]),
            'ip-pools' => $request->validate([
                'name' => ['required', 'string', 'max:128'],
                'ranges' => ['required', 'string', 'max:255', 'regex:/^[0-9a-fA-F:.,\/-]+$/'],
                'next-pool' => ['nullable', 'string', 'max:128'],
            ]),
            'dhcp-networks' => $request->validate([
                'address' => ['required', 'string', 'max:128', 'regex:/^[0-9a-fA-F:.\/]+$/'],
                'gateway' => ['nullable', 'ip'],
                'dns-server' => ['nullable', 'string', 'max:255', 'regex:/^[0-9a-fA-F:., ]+$/'],
                'domain' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
                'comment' => ['nullable', 'string', 'max:255'],
            ]),
            default => abort(404),
        };
    }

    public function storeExpanded(Request $request, Router $router, string $module)
    {
        $data = $this->normalizeRouterBooleans($this->expandedData($request, $module));
        $methods = ['hotspot-servers' => 'createHotspotServer', 'walled-garden' => 'createWalledGardenEntry', 'ip-pools' => 'createIpPool', 'dhcp-networks' => 'createDhcpNetwork'];

        return $this->result($this->service($router)->{$methods[$module]}($data));
    }

    public function updateExpanded(Request $request, Router $router, string $module, string $itemId)
    {
        $data = $this->normalizeRouterBooleans($this->expandedData($request, $module));
        $methods = ['hotspot-servers' => 'updateHotspotServer', 'walled-garden' => 'updateWalledGardenEntry', 'ip-pools' => 'updateIpPool', 'dhcp-networks' => 'updateDhcpNetwork'];

        return $this->result($this->service($router)->{$methods[$module]}($itemId, $data));
    }

    public function destroyExpanded(Router $router, string $module, string $itemId)
    {
        $methods = ['hotspot-servers' => 'removeHotspotServer', 'walled-garden' => 'removeWalledGardenEntry', 'ip-pools' => 'removeIpPool', 'dhcp-networks' => 'removeDhcpNetwork', 'hotspot-cookies' => 'removeHotspotCookie'];
        abort_unless(isset($methods[$module]), 404);

        return $this->result($this->service($router)->{$methods[$module]}($itemId));
    }

    private function normalizeRouterBooleans(array $data): array
    {
        if (array_key_exists('disabled', $data)) {
            $data['disabled'] = $data['disabled'] ? 'yes' : 'no';
        }

        return $data;
    }

    public function systemInformation(Router $router)
    {
        $service = $this->service($router);

        return response()->json([
            'identity' => $service->getSystemIdentity()['data'][0] ?? [],
            'clock' => $service->getSystemClock()['data'][0] ?? [],
            'health' => $service->getSystemHealth()['data'] ?? [],
            'ntp' => $service->getNtpClient()['data'][0] ?? [],
            'updates' => $service->getPackageUpdateStatus()['data'][0] ?? [],
        ]);
    }

    public function diagnostic(Request $request, Router $router)
    {
        $data = $request->validate(['tool' => ['required', Rule::in(['ping', 'traceroute'])], 'address' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9:.-]+$/']]);

        return $this->result($this->service($router)->{$data['tool']}($data['address']));
    }

    public function terminal(Request $request, Router $router)
    {
        $data = $request->validate([
            'command' => ['required', 'string', 'max:1000'],
            'confirmation' => ['nullable', 'string'],
        ]);
        $command = preg_replace('/\s+/', ' ', trim($data['command']));

        abort_if((bool) preg_match('/[\x00-\x1F\x7F;|&`$<>]/', $command), 422, 'Command chaining and control characters are not allowed.');
        abort_unless(str_starts_with($command, '/'), 422, 'RouterOS commands must start with /.');
        $readOnly = (bool) preg_match('#\s(?:print|monitor|get)(?:\s|$)#i', $command)
            || (bool) preg_match('#^/(?:ping|tool(?:/|\s+)traceroute)(?:\s|$)#i', $command);
        if (! $readOnly) {
            abort_unless($request->user()->isSuperAdmin(), 403, 'Only a super administrator can change router configuration from the terminal.');
            abort_unless(($data['confirmation'] ?? null) === 'EXECUTE', 422, 'Confirm this router-changing command before execution.');
        }

        $result = $this->service($router)->executeTerminal($command);
        if ($result['success']) {
            $sensitive = ['password', 'private-key', 'shared-secret', 'secret'];
            $result['data'] = array_map(function ($row) use ($sensitive) {
                if (! is_array($row)) {
                    return $row;
                }
                foreach ($sensitive as $key) {
                    unset($row[$key]);
                }

                return $row;
            }, $result['data'] ?? []);
        }
        ActivityLog::record('mikrotik.terminal.executed', json_encode([
            'router_id' => $router->id, 'command' => $this->redactTerminalCommand($command), 'read_only' => $readOnly,
            'outcome' => $result['success'] ? 'success' : 'failed',
        ], JSON_UNESCAPED_SLASHES), ['user_id' => $request->user()->id]);

        return $this->result($result);
    }

    private function redactTerminalCommand(string $command): string
    {
        return preg_replace(
            '/\b(password|private-key|shared-secret|secret)=(("[^"]*")|\S+)/i',
            '$1=[REDACTED]',
            $command
        );
    }

    public function bridges(Router $router)
    {
        return $this->result($this->service($router)->getBridges());
    }

    public function bridgePorts(Router $router)
    {
        $service = $this->service($router);
        $ports = $service->getBridgePorts();
        if (! $ports['success']) {
            return $this->result($ports);
        }
        $bridges = $service->getBridges();
        $interfaces = $service->getInterfaces();

        return response()->json([
            'data' => $ports['data'],
            'bridges' => $bridges['success'] ? $bridges['data'] : [],
            'interfaces' => $interfaces['success'] ? $interfaces['data'] : [],
        ]);
    }

    public function bridgeHosts(Router $router)
    {
        return $this->result($this->service($router)->getBridgeHosts());
    }

    private function bridgeData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'protocol-mode' => ['nullable', Rule::in(['none', 'stp', 'rstp', 'mstp'])],
            'vlan-filtering' => ['sometimes', 'boolean'],
            'comment' => ['nullable', 'string', 'max:255'],
            'disabled' => ['sometimes', 'boolean'],
        ]);
        foreach (['vlan-filtering', 'disabled'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $data[$key] ? 'yes' : 'no';
            }
        }

        return $data;
    }

    public function storeBridge(Request $request, Router $router)
    {
        return $this->result($this->service($router)->createBridge($this->bridgeData($request)));
    }

    public function updateBridge(Request $request, Router $router, string $bridgeId)
    {
        return $this->result($this->service($router)->updateBridge($bridgeId, $this->bridgeData($request)));
    }

    public function destroyBridge(Router $router, string $bridgeId)
    {
        $service = $this->service($router);
        $bridges = $service->getBridges();
        if (! $bridges['success']) {
            return $this->result($bridges);
        }
        $bridge = collect($bridges['data'])->firstWhere('.id', $bridgeId);
        abort_unless($bridge && ! $this->routerTrue($bridge['dynamic'] ?? false), 422, 'Dynamic or unknown bridges cannot be deleted.');
        $ports = $service->getBridgePorts();
        if (! $ports['success']) {
            return $this->result($ports);
        }
        abort_if(collect($ports['data'])->contains(fn ($port) => ($port['bridge'] ?? null) === ($bridge['name'] ?? null)), 422, 'Remove or move every bridge port before deleting this bridge.');

        return $this->result($service->removeBridge($bridgeId));
    }

    private function bridgePortData(Request $request, bool $moving = false): array
    {
        $priority = $request->input('priority');
        if (is_string($priority) && preg_match('/^0x[0-9a-f]+$/i', $priority)) {
            $request->merge(['priority' => hexdec(substr($priority, 2))]);
        }
        foreach (['hw', 'disabled'] as $booleanField) {
            $value = $request->input($booleanField);
            if (is_string($value) && in_array(strtolower($value), ['true', 'false', 'yes', 'no'], true)) {
                $request->merge([$booleanField => in_array(strtolower($value), ['true', 'yes'], true)]);
            }
        }

        $rules = [
            'bridge' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'interface' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'pvid' => ['nullable', 'integer', 'min:1', 'max:4094'],
            'path-cost' => ['nullable', 'integer', 'min:1', 'max:200000000'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:255'],
            'edge' => ['nullable', Rule::in(['auto', 'yes', 'no'])],
            'hw' => ['sometimes', 'boolean'],
            'disabled' => ['sometimes', 'boolean'],
        ];
        if ($moving) {
            $rules['confirmation'] = ['required', 'string'];
        }
        $data = $request->validate($rules);
        $this->rejectProtectedInterface($data['interface']);
        unset($data['confirmation']);
        foreach (['hw', 'disabled'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $data[$key] ? 'yes' : 'no';
            }
        }

        return $data;
    }

    public function storeBridgePort(Request $request, Router $router)
    {
        $data = $this->bridgePortData($request);
        $result = $this->service($router)->createBridgePort($data);
        $this->auditBridgePort($request, $router, $data['interface'], null, $data['bridge'], $result['success']);

        return $this->result($result);
    }

    public function updateBridgePort(Request $request, Router $router, string $portId)
    {
        $request->validate(['confirmation' => ['required', 'string', 'max:128']]);
        $service = $this->service($router);
        $ports = $service->getBridgePorts();
        if (! $ports['success']) {
            return $this->result($ports);
        }
        $current = $this->findBridgePort($ports['data'], $portId, (string) $request->input('confirmation'));
        abort_unless($current, 404, 'The bridge port no longer exists. Refresh the page and try again.');
        abort_if($this->routerTrue($current['dynamic'] ?? false), 422, 'Dynamic bridge ports cannot be changed.');
        $this->rejectProtectedInterface($current['interface'] ?? '');
        abort_unless(hash_equals((string) ($current['interface'] ?? ''), (string) $request->input('confirmation')), 422, 'Type the current interface name to confirm this bridge-port change.');
        $data = $this->bridgePortData($request);
        $result = $service->updateBridgePort((string) $current['.id'], $data);
        $this->auditBridgePort($request, $router, $data['interface'], $current['bridge'] ?? null, $data['bridge'], $result['success']);

        return $this->result($result);
    }

    public function destroyBridgePort(Request $request, Router $router, string $portId)
    {
        $request->validate(['confirmation' => ['required', 'string', 'max:128']]);
        $service = $this->service($router);
        $ports = $service->getBridgePorts();
        if (! $ports['success']) {
            return $this->result($ports);
        }
        $current = $this->findBridgePort($ports['data'], $portId, (string) $request->input('confirmation'));
        abort_unless($current, 404, 'The bridge port no longer exists. Refresh the page and try again.');
        abort_if($this->routerTrue($current['dynamic'] ?? false), 422, 'Dynamic bridge ports cannot be removed.');
        $interface = (string) ($current['interface'] ?? '');
        $this->rejectProtectedInterface($interface);
        abort_unless(hash_equals($interface, (string) $request->input('confirmation')), 422, 'Type the interface name to confirm removal.');
        $result = $service->removeBridgePort((string) $current['.id']);
        $this->auditBridgePort($request, $router, $interface, $current['bridge'] ?? null, null, $result['success']);

        return $this->result($result);
    }

    private function rejectProtectedInterface(string $interface): void
    {
        $protected = array_map('strtolower', config('mikrotik_security.protected_interfaces'));
        abort_if(in_array(strtolower($interface), $protected, true), 422, 'This interface is protected from bridge changes.');
    }

    private function findBridgePort(array $ports, string $portId, string $confirmedInterface): ?array
    {
        $decodedId = rawurldecode($portId);
        $current = collect($ports)->first(
            fn (array $port) => hash_equals((string) ($port['.id'] ?? ''), $decodedId)
        );

        if ($current || $confirmedInterface === '') {
            return $current;
        }

        return collect($ports)->first(
            fn (array $port) => hash_equals((string) ($port['interface'] ?? ''), $confirmedInterface)
        );
    }

    private function routerTrue(mixed $value): bool
    {
        return $value === true || in_array(strtolower((string) $value), ['true', 'yes'], true);
    }

    private function auditBridgePort(Request $request, Router $router, string $interface, ?string $oldBridge, ?string $newBridge, bool $success): void
    {
        ActivityLog::record('mikrotik.bridge_port.changed', json_encode([
            'router_id' => $router->id,
            'interface' => $interface,
            'old_bridge' => $oldBridge,
            'new_bridge' => $newBridge,
            'outcome' => $success ? 'success' : 'failed',
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
        ], JSON_UNESCAPED_SLASHES), ['user_id' => $request->user()->id]);
    }
}
