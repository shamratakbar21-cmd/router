<?php

namespace App\Http\Controllers;

use App\Models\RouterNode;
use App\Services\AuditLogWriter;
use App\Services\NeighborCapabilities;
use App\Services\RouterConfigurationManager;
use App\Services\RouterStateProjector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RouterDesktopMutationController extends Controller
{
    public function __invoke(Request $request, RouterNode $router, RouterConfigurationManager $manager, RouterStateProjector $projector): JsonResponse
    {
        $this->authorize('update', $router);
        $input = $request->validate([
            'module' => ['required', Rule::in(['arp', 'addresses', 'routes', 'dhcp', 'dns', 'filter', 'nat', 'devices', 'ports', 'bridge_vlans', 'pppoe', 'wg_interfaces', 'wg_peers', 'reservations', 'ipv6_addresses', 'ipv6_routes'])],
            'action' => ['required', Rule::in(['add', 'update', 'remove', 'enable', 'disable', 'comment', 'move_up', 'move_down'])],
            'index' => ['nullable', 'integer', 'min:0'],
            'expected_revision_id' => ['present', 'nullable', 'integer'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'entry' => ['sometimes', 'array'],
        ]);

        try {
            $revision = DB::transaction(function () use ($request, $router, $manager, $input) {
                $node = RouterNode::query()->lockForUpdate()->findOrFail($router->id);
                abort_if($node->desired_revision_id !== ($input['expected_revision_id'] ?? null)
                    || $node->configuration_lock_version !== $input['expected_lock_version'], 409, 'Configuration changed. Refresh before editing.');
                abort_if($node->safe_mode_status === 'pending_confirm', 409, 'Finish the pending configuration operation first.');
                $configuration = $node->desired_configuration ?? [];
                $secrets = $node->desired_secret_configuration ?? [];
                $module = $input['module'];
                abort_if($module === 'arp' && ! NeighborCapabilities::ready($node, $node->latestObservedState?->state ?? []), 409, 'Neighbor API 1 operations and reboot persistence must be verified before editing ARP.');
                $action = $input['action'];
                $path = ['arp' => 'static_neighbors', 'addresses' => 'addresses', 'routes' => 'routes', 'dhcp' => 'services.dhcp_servers', 'dns' => 'services.dns', 'filter' => 'firewall.rules', 'nat' => 'firewall.nat', 'devices' => 'interfaces', 'ports' => 'interfaces', 'bridge_vlans' => 'bridge_vlans', 'pppoe' => 'services.pppoe_clients', 'wg_interfaces' => 'services.wireguard.interfaces', 'wg_peers' => 'services.wireguard.peers', 'reservations' => 'services.dhcp_reservations', 'ipv6_addresses' => 'services.ipv6.addresses', 'ipv6_routes' => 'services.ipv6.routes'][$module];
                $rows = array_values(data_get($configuration, $path, []));
                $index = $input['index'] ?? null;

                if ($module === 'ports') {
                    if (! in_array($action, ['add', 'update', 'remove'], true)) {
                        throw ValidationException::withMessages(['action' => 'Bridge ports support add, update and remove.']);
                    }
                    if ($action !== 'add' && ($index === null || ! isset($rows[$index]['master']))) {
                        throw ValidationException::withMessages(['index' => 'Select a configured bridge port.']);
                    }
                    if ($action === 'remove') {
                        $port = $rows[$index]['name'];
                        if (collect($configuration['bridge_vlans'] ?? [])->contains('port', $port)) {
                            throw ValidationException::withMessages(['index' => 'Remove explicit VLAN memberships for this port first.']);
                        }
                        unset($rows[$index]['master']);
                    } else {
                        $bridges = collect($rows)->where('type', 'bridge')->pluck('name')->all();
                        $known = collect($rows)->pluck('name')->merge(collect($configuration['addresses'] ?? [])->pluck('interface'))->unique()->all();
                        $entry = Validator::make($input['entry'] ?? [], ['name' => ['required', Rule::in($known)], 'master' => ['required', Rule::in($bridges)]])->validate();
                        if ($action === 'update' && $entry['name'] !== $rows[$index]['name']) {
                            throw ValidationException::withMessages(['entry.name' => 'Remove the old port before selecting a different interface.']);
                        }
                        $port = $entry['name'];
                        $existingIndex = collect($rows)->search(fn ($row) => $row['name'] === $port);
                        $existing = $existingIndex === false ? ['name' => $port, 'type' => 'ether'] : $rows[$existingIndex];
                        if ($port === $node->management_interface || $port === $entry['master'] || ($existing['type'] ?? '') === 'bridge'
                            || collect($configuration['addresses'] ?? [])->contains('interface', $port)
                            || collect($configuration['routes'] ?? [])->contains('device', $port)
                            || collect($rows)->contains('parent', $port)) {
                            throw ValidationException::withMessages(['entry.name' => 'Use an unaddressed leaf interface. Management, routed interfaces and bridge nesting are protected.']);
                        }
                        if ($action === 'add' && ! empty($existing['master'])) {
                            throw ValidationException::withMessages(['entry.name' => 'This interface already belongs to a bridge. Edit its port instead.']);
                        }
                        if ($action === 'update' && ($existing['master'] ?? '') !== $entry['master'] && collect($configuration['bridge_vlans'] ?? [])->contains('port', $port)) {
                            throw ValidationException::withMessages(['entry.master' => 'Remove explicit VLAN memberships before changing the bridge.']);
                        }
                        $updated = [...$existing, 'master' => $entry['master']];
                        if ($existingIndex === false) {
                            $rows[] = $updated;
                        } else {
                            $rows[$existingIndex] = $updated;
                        }
                    }
                    data_set($configuration, $path, array_values($rows));
                } elseif ($module === 'dns') {
                    if ($action !== 'update') {
                        throw ValidationException::withMessages(['action' => 'DNS settings use the update action.']);
                    }
                    $entry = Validator::make($input['entry'] ?? [], [
                        'forwarders' => ['present', 'array', 'max:8'],
                        'forwarders.*' => ['required', 'ip'],
                        'search_domains' => ['present', 'array', 'max:8'],
                        'search_domains.*' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9.-]+$/'],
                    ])->validate();
                    data_set($configuration, $path, $entry);
                } else {
                    if ($action !== 'add' && ($index === null || ! array_key_exists($index, $rows))) {
                        throw ValidationException::withMessages(['index' => 'Select a configured entry. Dynamic entries can be made static using New.']);
                    }
                    if (in_array($action, ['enable', 'disable', 'comment'], true) && ! in_array($module, ['arp', 'filter', 'nat', 'reservations'], true)) {
                        throw ValidationException::withMessages(['action' => 'This action is supported for ARP, filter, and NAT entries.']);
                    }
                    if ($module === 'wg_interfaces' && $action !== 'add') {
                        $name = $rows[$index]['name'];
                        if ($action === 'update' && ($input['entry']['name'] ?? '') !== $name) {
                            throw ValidationException::withMessages(['entry.name' => 'Remove and recreate a WireGuard interface to rename it.']);
                        }
                        if ($action === 'remove') {
                            if (collect(data_get($configuration, 'services.wireguard.peers', []))->contains('interface', $name)
                                || collect([...data_get($configuration, 'firewall.rules', []), ...data_get($configuration, 'firewall.nat', [])])->contains(fn ($rule) => ($rule['in_interface'] ?? '') === $name || ($rule['out_interface'] ?? '') === $name)) {
                                throw ValidationException::withMessages(['index' => 'Remove dependent peers and firewall rules before deleting this interface.']);
                            }
                            unset($secrets['wireguard'][$name]);
                        }
                    }
                    if ($module === 'pppoe') {
                        if ($action === 'update' && ($input['entry']['name'] ?? '') !== ($rows[$index]['name'] ?? '')) {
                            throw ValidationException::withMessages(['entry.name' => 'Remove and recreate a PPPoE client to rename it.']);
                        }
                        if ($action === 'remove') {
                            unset($secrets['services']['pppoe_clients'][$rows[$index]['name']]);
                        }
                    }
                    if ($module === 'devices' && $action !== 'add') {
                        $existing = $rows[$index];
                        if ($action === 'update' && ($input['entry']['type'] ?? '') !== ($existing['type'] ?? '')) {
                            throw ValidationException::withMessages(['entry.type' => 'Create a new device to change its type.']);
                        }
                        if (! in_array($existing['type'] ?? '', ['bridge', 'vlan'], true)) {
                            throw ValidationException::withMessages(['index' => 'Physical interfaces are read-only in this editor.']);
                        }
                        if ($action === 'remove' || ($action === 'update' && ($input['entry']['name'] ?? '') !== $existing['name'])) {
                            $name = $existing['name'];
                            $used = $name === $node->management_interface
                                || collect($configuration['addresses'] ?? [])->contains('interface', $name)
                                || collect($configuration['routes'] ?? [])->contains('device', $name)
                                || collect($configuration['static_neighbors'] ?? [])->contains('interface', $name)
                                || collect(data_get($configuration, 'services.dhcp_servers', []))->contains('interface', $name)
                                || collect(data_get($configuration, 'services.pppoe_clients', []))->contains('interface', $name)
                                || collect([...data_get($configuration, 'firewall.rules', []), ...data_get($configuration, 'firewall.nat', [])])->contains(fn ($rule) => ($rule['in_interface'] ?? '') === $name || ($rule['out_interface'] ?? '') === $name)
                                || collect($rows)->contains(fn ($row) => ($row['parent'] ?? '') === $name || ($row['master'] ?? '') === $name)
                                || collect($configuration['bridge_vlans'] ?? [])->contains('device', $name);
                            if ($used) {
                                throw ValidationException::withMessages(['entry.name' => 'Remove dependent addresses, routes, ports and VLANs before deleting or renaming this device.']);
                            }
                        }
                    }
                    if (in_array($action, ['move_up', 'move_down'], true)) {
                        if (! in_array($module, ['filter', 'nat'], true)) {
                            throw ValidationException::withMessages(['action' => 'Only firewall and NAT rules can be reordered.']);
                        }
                        $target = $index + ($action === 'move_up' ? -1 : 1);
                        if (! array_key_exists($target, $rows)) {
                            throw ValidationException::withMessages(['index' => 'The rule is already at the edge of the list.']);
                        }
                        [$rows[$index], $rows[$target]] = [$rows[$target], $rows[$index]];
                    } elseif ($action === 'remove') {
                        array_splice($rows, $index, 1);
                    } elseif (in_array($action, ['enable', 'disable'], true)) {
                        $rows[$index]['disabled'] = $action === 'disable';
                    } elseif ($action === 'comment') {
                        $entry = Validator::make($input['entry'] ?? [], ['comment' => ['nullable', 'string', 'max:240']])->validate();
                        $rows[$index]['comment'] = $entry['comment'] ?? '';
                    } else {
                        $entry = $this->entry($module, $input['entry'] ?? [], $node);
                        if ($module === 'wg_interfaces') {
                            $supplied = (string) ($input['entry']['private_key'] ?? '');
                            $private = $supplied !== '' ? $supplied : ($secrets['wireguard'][$entry['name']]['private_key'] ?? base64_encode(random_bytes(32)));
                            $bytes = base64_decode($private, true);
                            if ($bytes === false || strlen($bytes) !== 32) {
                                throw ValidationException::withMessages(['entry.private_key' => 'Use a base64-encoded 32-byte WireGuard private key, or leave blank to generate one.']);
                            }
                            $entry['public_key'] = base64_encode(sodium_crypto_scalarmult_base($bytes));
                            $secrets['wireguard'][$entry['name']]['private_key'] = $private;
                        }

                        if ($module === 'pppoe') {
                            $secret = Validator::make($input['entry'] ?? [], ['password_action' => ['sometimes', 'in:retain,replace,clear'], 'password' => ['nullable', 'string', 'max:255', 'regex:/^[^\x00-\x1f\x7f]*$/']])->validate();
                            $credentialAction = $secret['password_action'] ?? (! empty($secret['password']) ? 'replace' : 'retain');
                            $secrets['password_actions'][$entry['name']] = $credentialAction;
                            if ($credentialAction === 'replace' && empty($secret['password'])) {
                                throw ValidationException::withMessages(['entry.password' => 'A replacement password is required.']);
                            }
                            if (! empty($secret['password'])) {
                                $secrets['services']['pppoe_clients'][$entry['name']]['password'] = $secret['password'];
                            }
                            if ($action === 'add' && empty($secrets['services']['pppoe_clients'][$entry['name']]['password'])) {
                                throw ValidationException::withMessages(['entry.password' => 'A password is required for a new PPPoE client.']);
                            }
                        }
                        if ($action === 'add') {
                            if (count($rows) >= 256) {
                                throw ValidationException::withMessages(['entry' => 'Maximum 256 entries per list.']);
                            }
                            $rows[] = $entry;
                        } else {
                            $rows[$index] = $module === 'devices' ? [...$rows[$index], ...$entry] : $entry;
                        }
                    }
                    if ($module === 'ipv6_addresses') {
                        $keys = array_map(fn ($row) => $row['interface'].'|'.$row['cidr'], $rows);
                        if (count($keys) !== count(array_unique($keys))) {
                            throw ValidationException::withMessages(['entry.cidr' => 'This IPv6 address already exists on the interface.']);
                        }
                    }
                    if ($module === 'reservations') {
                        $macs = array_map(fn ($row) => strtolower($row['mac']), $rows);
                        $ips = array_column($rows, 'ip');
                        if (count($macs) !== count(array_unique($macs)) || count($ips) !== count(array_unique($ips))) {
                            throw ValidationException::withMessages(['entry' => 'A reservation with this MAC or IP address already exists.']);
                        }
                    }
                    if ($module === 'wg_interfaces') {
                        $names = array_column($rows, 'name');
                        $ports = array_column($rows, 'listen_port');
                        $otherNames = collect($configuration['interfaces'] ?? [])->pluck('name')->merge(collect($configuration['addresses'] ?? [])->pluck('interface'))->push($node->management_interface)->merge(collect(data_get($configuration, 'services.pppoe_clients', []))->pluck('name'))->all();
                        if (count($names) !== count(array_unique($names)) || count($ports) !== count(array_unique($ports)) || array_intersect($names, $otherNames)) {
                            throw ValidationException::withMessages(['entry.name' => 'WireGuard names and listen ports must be unique and names must not overlap another interface.']);
                        }
                    }
                    if ($module === 'wg_peers') {
                        $keys = array_map(fn ($row) => $row['interface'].'|'.$row['public_key'], $rows);
                        if (count($keys) !== count(array_unique($keys))) {
                            throw ValidationException::withMessages(['entry.public_key' => 'This peer key already exists on the interface.']);
                        }
                    }
                    if ($module === 'pppoe') {
                        $names = array_column($rows, 'name');
                        if (count($names) !== count(array_unique($names))) {
                            throw ValidationException::withMessages(['entry.name' => 'A PPPoE client with this name already exists.']);
                        }
                    }
                    if ($module === 'bridge_vlans') {
                        $keys = array_map(fn ($row) => $row['device'].'|'.$row['vid'].'|'.($row['port'] ?? ''), $rows);
                        if (count($keys) !== count(array_unique($keys))) {
                            throw ValidationException::withMessages(['entry' => 'This bridge, VLAN and port membership already exists.']);
                        }
                    }
                    if ($module === 'devices') {
                        $names = array_column($rows, 'name');
                        if (count($names) !== count(array_unique($names))) {
                            throw ValidationException::withMessages(['entry.name' => 'An interface with this name already exists.']);
                        }
                    }
                    if ($module === 'arp') {
                        $keys = array_map(fn ($row) => $row['interface'].'|'.$row['ip'], $rows);
                        if (count($keys) !== count(array_unique($keys))) {
                            throw ValidationException::withMessages(['entry.ip' => 'An ARP entry already exists for this address and interface.']);
                        }
                    }
                    data_set($configuration, $path, $rows);
                }

                $revision = $manager->updateDesiredState($node, $configuration, $secrets, $request->user());
                if ($module === 'arp') {
                    $index = $action === 'add' ? array_key_last($configuration['static_neighbors']) : ($input['index'] ?? null);
                    app(AuditLogWriter::class)->write('router.arp.staged', 'Staged ARP '.$action.'.', $node, $request->user(), metadata: [
                        'action' => $action, 'revision_id' => $revision->id,
                        'before' => $action === 'add' ? null : ($node->desired_configuration['static_neighbors'][$index] ?? null),
                        'after' => $action === 'remove' ? null : ($configuration['static_neighbors'][$index] ?? null),
                    ]);
                }

                return $revision;
            }, attempts: 5);
        } catch (RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => "Revision {$revision->revision_number} staged. Apply to update the appliance.",
            'state' => $projector->project($router->fresh()),
        ]);
    }

    public function entry(string $module, array $data, RouterNode $router): array
    {
        $interfaces = collect($router->desired_configuration['addresses'] ?? [])->pluck('interface')
            ->merge(collect($router->desired_configuration['interfaces'] ?? [])->pluck('name'))->merge(collect(data_get($router->desired_configuration, 'services.wireguard.interfaces', []))->pluck('name'))->push($router->management_interface)->unique()->values()->all();
        $interface = ['required', 'string', Rule::in($interfaces)];
        $optionalInterface = ['nullable', 'string', Rule::in($interfaces)];
        $firewallFields = [
            'protocol' => ['nullable', Rule::in(['all', 'tcp', 'udp', 'icmp'])],
            'source' => ['nullable', 'string'], 'destination' => ['nullable', 'string'],
            'source_port' => ['nullable', 'string'], 'destination_port' => ['nullable', 'string'],
            'in_interface' => $optionalInterface, 'out_interface' => $optionalInterface,
            'disabled' => ['sometimes', 'boolean'], 'comment' => ['nullable', 'string', 'max:240'],
        ];
        $rules = match ($module) {
            'ipv6_addresses' => ['interface' => $interface, 'cidr' => ['required', 'string']],
            'ipv6_routes' => ['interface' => ['required', Rule::in(collect(data_get($router->desired_configuration, 'services.ipv6.addresses', []))->pluck('interface')->all())], 'destination' => ['required', 'string'], 'gateway' => ['nullable', 'ipv6'], 'metric' => ['nullable', 'integer', 'between:0,65535']],
            'reservations' => ['interface' => ['required', Rule::in(collect(data_get($router->desired_configuration, 'services.dhcp_servers', []))->pluck('interface')->all())], 'ip' => ['required', 'ipv4'], 'mac' => ['required', 'mac_address'], 'hostname' => ['nullable', 'string', 'max:63', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/'], 'lease_time' => ['required', 'regex:/^[1-9][0-9]*[smhdw]$/'], 'disabled' => ['sometimes', 'boolean'], 'comment' => ['nullable', 'string', 'max:240']],
            'wg_interfaces' => ['name' => ['required', 'regex:/^[a-zA-Z][a-zA-Z0-9_]{0,14}$/'], 'listen_port' => ['required', 'integer', 'between:1,65535'], 'mtu' => ['nullable', 'integer', 'between:576,9000'], 'addresses' => ['required', 'array', 'min:1', 'max:8'], 'addresses.*' => ['required', 'string']],
            'wg_peers' => ['interface' => ['required', Rule::in(collect(data_get($router->desired_configuration, 'services.wireguard.interfaces', []))->pluck('name')->all())], 'public_key' => ['required', 'string'], 'allowed_ips' => ['required', 'array', 'min:1', 'max:32'], 'allowed_ips.*' => ['required', 'string'], 'endpoint_host' => ['nullable', 'string', 'max:253', 'regex:/^[a-zA-Z0-9.-]+$/'], 'endpoint_port' => ['nullable', 'integer', 'between:1,65535'], 'persistent_keepalive' => ['nullable', 'integer', 'between:0,65535']],
            'pppoe' => ['name' => ['required', 'string', 'max:15', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/'], 'interface' => $interface, 'username' => ['required', 'string', 'max:255'], 'service_name' => ['nullable', 'string', 'max:255'], 'default_route' => ['sometimes', 'boolean'], 'mtu' => ['nullable', 'integer', 'between:576,1492'], 'download_kbps' => ['nullable', 'integer', 'between:0,10000000'], 'upload_kbps' => ['nullable', 'integer', 'between:0,10000000']],
            'bridge_vlans' => ['device' => ['required', Rule::in(collect($router->desired_configuration['interfaces'] ?? [])->where('type', 'bridge')->pluck('name')->all())], 'vid' => ['required', 'integer', 'between:1,4094'], 'port' => ['nullable', 'string'], 'pvid' => ['sometimes', 'boolean'], 'untagged' => ['sometimes', 'boolean'], 'self' => ['sometimes', 'boolean']],
            'devices' => ['name' => ['required', 'string', 'max:15', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'], 'type' => ['required', Rule::in(['bridge', 'vlan'])], 'parent' => $optionalInterface, 'vlan_id' => ['nullable', 'integer', 'between:1,4094'], 'mtu' => ['nullable', 'integer', 'between:576,9000'], 'vlan_filtering' => ['sometimes', 'boolean']],
            'filter' => [...$firewallFields, 'chain' => ['required', Rule::in(['input', 'forward', 'output'])], 'action' => ['required', Rule::in(['accept', 'drop', 'reject'])]],
            'nat' => [...$firewallFields, 'chain' => ['required', Rule::in(['prerouting', 'postrouting'])], 'action' => ['required', Rule::in(['masquerade', 'snat', 'dnat'])], 'to_address' => ['nullable', 'ipv4'], 'to_port' => ['nullable', 'string']],
            'arp' => ['ip' => ['required', 'ipv4'], 'mac' => ['required', 'mac_address'], 'interface' => $interface, 'disabled' => ['sometimes', 'boolean'], 'comment' => ['nullable', 'string', 'max:240']],
            'addresses' => ['interface' => $interface, 'cidr' => ['required', 'string']],
            'routes' => ['destination' => ['required', 'string'], 'gateway' => ['nullable', 'ipv4'], 'device' => $interface, 'metric' => ['nullable', 'integer', 'min:0', 'max:65535']],
            'dhcp' => ['interface' => $interface, 'start' => ['required', 'integer', 'min:1', 'max:254'], 'limit' => ['required', 'integer', 'min:1', 'max:254'], 'lease_time' => ['required', 'regex:/^[1-9][0-9]*[smhdw]$/'], 'domain' => ['nullable', 'string', 'max:253', 'regex:/^[a-zA-Z0-9.-]*$/'], 'dns_servers' => ['sometimes', 'array', 'max:4'], 'dns_servers.*' => ['required', 'ipv4']],
        };
        $entry = Validator::make($data, $rules)->validate();
        if (in_array($module, ['ipv6_addresses', 'ipv6_routes'], true)) {
            if ($entry['interface'] === $router->management_interface) {
                throw ValidationException::withMessages(['entry.interface' => 'Management IPv6 configuration is not supported in this editor.']);
            }
            $field = $module === 'ipv6_addresses' ? 'cidr' : 'destination';
            $parts = explode('/', $entry[$field]);
            if (count($parts) !== 2 || ! filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) || ! ctype_digit($parts[1]) || (int) $parts[1] > 128) {
                throw ValidationException::withMessages(['entry.'.$field => 'Enter an IPv6 address and prefix, such as fd77:77::1/64.']);
            }
        }
        if ($module === 'reservations') {
            $ip = ip2long($entry['ip']);
            $valid = collect($router->desired_configuration['addresses'] ?? [])->where('interface', $entry['interface'])->contains(function ($row) use ($entry, $ip) {
                [$address, $prefix] = explode('/', $row['cidr']);
                $prefix = (int) $prefix;
                if ($prefix < 1 || $prefix > 30 || $entry['ip'] === $address) {
                    return false;
                }
                $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
                $network = ip2long($address) & $mask;
                $broadcast = $network | ((~$mask) & 0xFFFFFFFF);

                return ($ip & $mask) === $network && $ip !== $network && $ip !== $broadcast;
            });
            if (! $valid) {
                throw ValidationException::withMessages(['entry.ip' => 'Choose a usable host address in the DHCP interface subnet, different from the router address.']);
            }
            $entry['mac'] = strtolower($entry['mac']);
            $entry['disabled'] = (bool) ($entry['disabled'] ?? false);
        }
        if ($module === 'wg_interfaces') {
            foreach ($entry['addresses'] as $address) {
                $this->cidr($address, 'entry.addresses');
            }
        }
        if ($module === 'wg_peers') {
            $decoded = base64_decode($entry['public_key'], true);
            if ($decoded === false || strlen($decoded) !== 32) {
                throw ValidationException::withMessages(['entry.public_key' => 'Use a base64-encoded 32-byte public key.']);
            }
            foreach ($entry['allowed_ips'] as $cidr) {
                $this->cidr($cidr, 'entry.allowed_ips');
            }
            if (! empty($entry['endpoint_host']) && empty($entry['endpoint_port'])) {
                throw ValidationException::withMessages(['entry.endpoint_port' => 'Enter the peer endpoint port.']);
            }
        }
        if ($module === 'pppoe' && ($entry['interface'] === $router->management_interface || $entry['name'] === $router->management_interface)) {
            throw ValidationException::withMessages(['entry.interface' => 'Use a non-management interface and name for PPPoE.']);
        }
        if ($module === 'bridge_vlans') {
            $port = $entry['port'] ?? '';
            if ($port !== '') {
                $member = collect($router->desired_configuration['interfaces'] ?? [])->firstWhere('name', $port);
                if (($member['master'] ?? '') !== $entry['device']) {
                    throw ValidationException::withMessages(['entry.port' => 'Select a port belonging to this bridge, or leave blank for all member ports.']);
                }
            }
            if (($entry['pvid'] ?? false) && ! ($entry['untagged'] ?? false)) {
                throw ValidationException::withMessages(['entry.pvid' => 'PVID requires untagged membership.']);
            }
        }
        if ($module === 'devices') {
            if ($entry['name'] === $router->management_interface || preg_match('/^(eth[0-9]+|lo|wan[0-9]*|mgmt[0-9]*)$/', $entry['name'])) {
                throw ValidationException::withMessages(['entry.name' => 'Choose a new logical interface name, such as br-test or vlan100.']);
            }
            if ($entry['type'] === 'vlan') {
                if (empty($entry['parent']) || empty($entry['vlan_id']) || $entry['parent'] === $entry['name']) {
                    throw ValidationException::withMessages(['entry.parent' => 'VLAN requires a different parent interface and VLAN ID.']);
                }
                $parent = collect($router->desired_configuration['interfaces'] ?? [])->firstWhere('name', $entry['parent']);
                if (($parent['type'] ?? '') === 'vlan') {
                    throw ValidationException::withMessages(['entry.parent' => 'Nested VLAN devices are not supported by this editor.']);
                }
                unset($entry['vlan_filtering']);
            } else {
                unset($entry['parent'], $entry['vlan_id']);
            }
        }
        if (in_array($module, ['filter', 'nat'], true)) {
            foreach (['source', 'destination'] as $field) {
                if (! empty($entry[$field])) {
                    if (str_contains($entry[$field], '/')) {
                        $this->cidr($entry[$field], 'entry.'.$field);
                    } elseif (! filter_var($entry[$field], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        throw ValidationException::withMessages(['entry.'.$field => 'Enter an IPv4 address or CIDR.']);
                    }
                }
            }
            foreach (['source_port', 'destination_port', 'to_port'] as $field) {
                if (empty($entry[$field])) {
                    continue;
                }
                $ports = explode('-', $entry[$field]);
                if (count($ports) > 2 || count(array_filter($ports, fn ($port) => ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535)) !== count($ports)
                    || (count($ports) === 2 && (int) $ports[0] > (int) $ports[1])) {
                    throw ValidationException::withMessages(['entry.'.$field => 'Use a port from 1–65535 or an ascending range.']);
                }
                if (! in_array($entry['protocol'] ?? '', ['tcp', 'udp'], true)) {
                    throw ValidationException::withMessages(['entry.protocol' => 'Port matching requires TCP or UDP.']);
                }
            }
            if ($module === 'filter') {
                $input = ! empty($entry['in_interface']);
                $output = ! empty($entry['out_interface']);
                $valid = match ($entry['chain']) {
                    'input' => $input && ! $output, 'output' => ! $input && $output, 'forward' => $input && $output
                };
                if (! $valid) {
                    throw ValidationException::withMessages(['entry.chain' => 'Input needs an incoming interface, output an outgoing interface, and forward both.']);
                }
            } else {
                $dnat = $entry['action'] === 'dnat';
                if ($entry['chain'] !== ($dnat ? 'prerouting' : 'postrouting') || empty($entry['out_interface'])
                    || ($dnat && empty($entry['in_interface'])) || ($entry['action'] !== 'masquerade' && empty($entry['to_address']))) {
                    throw ValidationException::withMessages(['entry' => 'DNAT needs prerouting, both interfaces and a target address. Source NAT needs postrouting and an outgoing interface; SNAT also needs a target address.']);
                }
                if (! $dnat && ! empty($entry['in_interface'])) {
                    throw ValidationException::withMessages(['entry.in_interface' => 'Source NAT matches the outgoing interface. Leave In Interface empty.']);
                }
                if ($entry['action'] === 'masquerade' && ! empty($entry['to_address'])) {
                    throw ValidationException::withMessages(['entry.to_address' => 'Masquerade uses the outgoing interface address. Leave To Address empty.']);
                }
            }
            $entry['disabled'] = (bool) ($entry['disabled'] ?? false);
        }
        if ($module === 'addresses') {
            $this->cidr($entry['cidr'], 'entry.cidr');
            if ($entry['interface'] === $router->management_interface) {
                throw ValidationException::withMessages(['entry.interface' => 'Change the management address in router settings.']);
            }
        }
        if ($module === 'routes' && $entry['destination'] !== 'default') {
            $this->cidr($entry['destination'], 'entry.destination');
        }
        if ($module === 'dhcp' && $entry['start'] + $entry['limit'] > 255) {
            throw ValidationException::withMessages(['entry.limit' => 'The DHCP pool must fit the /24 host range.']);
        }
        if ($module === 'arp') {
            $entry['mac'] = strtolower($entry['mac']);
            $entry['disabled'] = (bool) ($entry['disabled'] ?? false);
        }

        return $entry;
    }

    private function cidr(string $cidr, string $field): void
    {
        $parts = explode('/', $cidr);
        if (count($parts) !== 2 || ! filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || ! ctype_digit($parts[1]) || (int) $parts[1] > 32) {
            throw ValidationException::withMessages([$field => 'Enter an IPv4 address with a prefix, such as 192.168.77.1/24.']);
        }
    }
}
