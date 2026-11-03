<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class OpenWrtRuntimeConfigRenderer
{
    private array $sectionSources = [];

    private function assertSafeValues(array $values): void
    {
        foreach ($values as $key => $value) {
            if ($key === 'comment') {
                continue;
            }
            if (is_array($value)) {
                $this->assertSafeValues($value);
            } elseif (is_string($value) && (preg_match('/[\x00-\x1f\x7f]/', $value))) {
                throw new \InvalidArgumentException('Unsafe UCI value.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $secretConfiguration
     * @return array<string, string>
     */
    public function render(array $configuration, array $secretConfiguration = [], bool $maskSecrets = true): array
    {
        $this->sectionSources = [];
        if (Arr::has($configuration, 'forwarding.ipv4') && ! $this->truthy(Arr::get($configuration, 'forwarding.ipv4'))) {
            throw new \InvalidArgumentException('Disabling kernel IPv4 forwarding is unsupported by the enrolled runtime overlay protocol. Use explicit firewall drop rules; no partial apply was generated.');
        }
        $this->assertSafeValues($configuration);
        $this->assertSafeValues($secretConfiguration);

        return [
            'network' => $this->renderNetwork($configuration, $secretConfiguration, $maskSecrets).$this->renderWireGuard($configuration, $secretConfiguration, $maskSecrets).$this->renderIpv6($configuration),
            'dhcp' => $this->renderDhcp($configuration),
            'firewall' => $this->renderFirewall($configuration),
            'pppoe_shaping' => $this->renderPppoeShaping($configuration),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function renderIpv6(array $configuration): string
    {
        $lines = [];
        foreach (collect(Arr::get($configuration, 'services.ipv6.addresses', []))->groupBy('interface') as $device => $addresses) {
            $section = $this->uciName('nur6_'.$device);
            $lines = array_merge($lines, ['', UciSerializer::section('interface', $section), UciSerializer::entry('option', 'device', $device), "    option proto 'static'"]);
            foreach ($addresses as $address) {
                $lines[] = UciSerializer::entry('list', 'ip6addr', $address['cidr']);
            }
        }
        foreach (Arr::get($configuration, 'services.ipv6.routes', []) as $index => $route) {
            $interface = $this->uciName('nur6_'.$route['interface']);
            $lines = array_merge($lines, ['', UciSerializer::section('route6', "nur6_route_{$index}"), UciSerializer::entry('option', 'interface', $interface), UciSerializer::entry('option', 'target', $route['destination'])]);
            foreach (['gateway', 'metric'] as $field) {
                if (isset($route[$field]) && $route[$field] !== '') {
                    $lines[] = UciSerializer::entry('option', "{$field}", $route[$field]);
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function renderWireGuard(array $configuration, array $secrets, bool $maskSecrets): string
    {
        $lines = [];
        foreach (Arr::get($configuration, 'services.wireguard.interfaces', []) as $interface) {
            $name = $interface['name'];
            $key = $secrets['wireguard'][$name]['private_key'] ?? '';
            $lines = array_merge($lines, ['', UciSerializer::section('interface', $name), UciSerializer::entry('option', 'proto', 'wireguard'), UciSerializer::entry('option', 'private_key', $maskSecrets ? '***redacted***' : $key), UciSerializer::entry('option', 'listen_port', $interface['listen_port'])]);
            if (! empty($interface['mtu'])) {
                $lines[] = UciSerializer::entry('option', 'mtu', $interface['mtu']);
            }
            foreach ($interface['addresses'] as $address) {
                $lines[] = UciSerializer::entry('list', 'addresses', $address);
            }
            foreach (Arr::get($configuration, 'services.wireguard.peers', []) as $peer) {
                if ($peer['interface'] !== $name) {
                    continue;
                }
                $lines = array_merge($lines, ['', UciSerializer::section('wireguard_'.$name), UciSerializer::entry('option', 'public_key', $peer['public_key']), "    option route_allowed_ips '1'"]);
                foreach ($peer['allowed_ips'] as $cidr) {
                    $lines[] = UciSerializer::entry('list', 'allowed_ips', $cidr);
                }
                foreach (['endpoint_host', 'endpoint_port', 'persistent_keepalive'] as $option) {
                    if (! empty($peer[$option])) {
                        $lines[] = UciSerializer::entry('option', "{$option}", $peer[$option]);
                    }
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function renderPppoeShaping(array $configuration): string
    {
        $profiles = collect(Arr::get($configuration, 'services.pppoe_clients', []))
            ->map(function (array $client): ?array {
                $download = (int) ($client['download_kbps'] ?? 0);
                $upload = (int) ($client['upload_kbps'] ?? 0);
                $name = (string) ($client['name'] ?? '');

                if ($name === '' || ($download <= 0 && $upload <= 0)) {
                    return null;
                }

                return [
                    'name' => $this->uciName($name),
                    'download_kbps' => max(0, $download),
                    'upload_kbps' => max(0, $upload),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $secretConfiguration
     */
    private function renderNetwork(array $configuration, array $secretConfiguration, bool $maskSecrets): string
    {
        $lines = [
            '# Generated NUR WinControl network preview',
            "config interface 'loopback'",
            "    option device 'lo'",
            "    option proto 'static'",
            "    option ipaddr '127.0.0.1'",
            "    option netmask '255.0.0.0'",
            '',
        ];

        $interfaces = collect(Arr::get($configuration, 'interfaces', []))
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['name'] ?? null))
            ->values();
        $bridgeVlanDevices = collect(Arr::get($configuration, 'bridge_vlans', []))
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['device'] ?? null))
            ->pluck('device')
            ->map(fn (mixed $name): string => (string) $name)
            ->unique()
            ->values()
            ->all();

        $lines = array_merge($lines, $this->renderBridgeDevices($interfaces, $bridgeVlanDevices));
        $lines = array_merge($lines, $this->renderVlanDevices($interfaces));
        $lines = array_merge($lines, $this->renderBridgeVlans($configuration, $interfaces));

        $managementInterface = (string) Arr::get($configuration, 'management.interface', 'mgmt0');
        $deviceAliases = $this->deviceAliases($interfaces);

        $lines[] = UciSerializer::section('interface', 'management');
        $lines[] = UciSerializer::entry('option', 'proto', 'static');
        $lines[] = UciSerializer::entry('option', 'device', ($deviceAliases[$managementInterface] ?? $managementInterface));

        $managementAddresses = collect(Arr::get($configuration, 'addresses', []))->where('interface', $managementInterface)->pluck('cidr')
            ->prepend(Arr::get($configuration, 'management.cidr'))->filter()->unique()->values();
        if ($managementAddresses->count() > 1) {
            foreach ($managementAddresses as $cidr) {
                $lines[] = UciSerializer::entry('list', 'ipaddr', $cidr);
            }
        } else {
            foreach ($this->cidrToAddressParts((string) $managementAddresses->first()) as $key => $value) {
                $lines[] = UciSerializer::entry('option', "{$key}", $value);
            }
        }

        $configuredInterfaces = collect(Arr::get($configuration, 'addresses', []))
            ->groupBy('interface')
            ->except([$managementInterface]);

        foreach ($configuredInterfaces as $interfaceName => $interfaceAddresses) {
            /** @var array<string, mixed>|null $primaryAddress */
            $primaryAddress = collect($interfaceAddresses)->first();
            $device = $deviceAliases[(string) $interfaceName] ?? (string) $interfaceName;
            $section = $this->uciName((string) $interfaceName);

            $lines[] = '';
            $lines[] = UciSerializer::section('interface', $section);
            $lines[] = UciSerializer::entry('option', 'device', $device);
            $lines[] = UciSerializer::entry('option', 'proto', 'static');

            if (count($interfaceAddresses) > 1) {
                foreach ($interfaceAddresses as $address) {
                    $lines[] = UciSerializer::entry('list', 'ipaddr', $address['cidr']);
                }
            } else {
                foreach ($this->cidrToAddressParts((string) ($primaryAddress['cidr'] ?? '')) as $key => $value) {
                    $lines[] = UciSerializer::entry('option', "{$key}", $value);
                }
            }
        }

        foreach (Arr::get($configuration, 'routes', []) as $index => $route) {
            $lines[] = '';
            $lines[] = UciSerializer::section('route', "generated_{$index}");
            $lines[] = UciSerializer::entry('option', 'interface', (($route['device'] ?? 'management') === $managementInterface ? 'management' : $this->uciName((string) ($route['device'] ?? 'management'))));
            $lines[] = UciSerializer::entry('option', 'target', (($route['destination'] ?? null) === 'default' ? '0.0.0.0/0' : (string) ($route['destination'] ?? '0.0.0.0/0')));

            if (($route['gateway'] ?? null) !== null && $route['gateway'] !== '') {
                $lines[] = UciSerializer::entry('option', 'gateway', ((string) $route['gateway']));
            }

            if (($route['metric'] ?? null) !== null && $route['metric'] !== '') {
                $lines[] = UciSerializer::entry('option', 'metric', ((string) $route['metric']));
            }
        }

        foreach (Arr::get($configuration, 'services.pppoe_clients', []) as $client) {
            $name = (string) ($client['name'] ?? 'pppoe');
            $password = (string) ($secretConfiguration['services']['pppoe_clients'][$name]['password'] ?? '');
            $pppoeDevice = (string) ($client['interface'] ?? 'eth0');

            $section = $this->uciName($name);
            $lines[] = '';
            $lines[] = UciSerializer::section('interface', $section);
            $lines[] = UciSerializer::entry('option', 'proto', 'pppoe');
            $lines[] = UciSerializer::entry('option', 'device', ($deviceAliases[$pppoeDevice] ?? $pppoeDevice));
            $lines[] = UciSerializer::entry('option', 'username', ((string) ($client['username'] ?? '')));

            if ($password !== '') {
                $lines[] = UciSerializer::entry('option', 'password', ($maskSecrets ? '***redacted***' : $password));
            }

            if (($client['service_name'] ?? null) !== null && $client['service_name'] !== '') {
                $lines[] = UciSerializer::entry('option', 'service', ((string) $client['service_name']));
            }

            if (($client['mtu'] ?? null) !== null && $client['mtu'] !== '') {
                $lines[] = UciSerializer::entry('option', 'mtu', ((string) $client['mtu']));
            }

            $lines[] = UciSerializer::entry('option', 'defaultroute', ($this->truthy($client['default_route'] ?? true) ? '1' : '0'));

            // Profile rates are applied by nur-pppoe-shape after bring-up; recorded here for operators.
            if ((int) ($client['download_kbps'] ?? 0) > 0) {
                $lines[] = '    # nur_download_kbps '.((int) $client['download_kbps']);
            }
            if ((int) ($client['upload_kbps'] ?? 0) > 0) {
                $lines[] = '    # nur_upload_kbps '.((int) $client['upload_kbps']);
            }
        }

        foreach (Arr::get($configuration, 'static_neighbors', []) as $index => $neighbor) {
            if ($this->truthy($neighbor['disabled'] ?? false)) {
                continue;
            }
            $interface = (string) $neighbor['interface'];
            $section = $interface === $managementInterface ? 'management' : $this->uciName($interface);
            $lines[] = '';
            $lines[] = UciSerializer::section('neighbor', "nur_arp_{$index}");
            $lines[] = UciSerializer::entry('option', 'interface', $section);
            $lines[] = UciSerializer::entry('option', 'ipaddr', $neighbor['ip']);
            $lines[] = UciSerializer::entry('option', 'mac', $neighbor['mac']);
        }

        $sections = [];
        foreach ($lines as $line) {
            if (preg_match("/^config [a-z-]+ '([^']+)'$/D", $line, $match)) {
                if (isset($sections[$match[1]])) {
                    throw new \InvalidArgumentException('Duplicate UCI section identifier.');
                }
                $sections[$match[1]] = true;
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $interfaces
     * @param  list<string>  $bridgeVlanDevices
     * @return list<string>
     */
    private function renderBridgeDevices($interfaces, array $bridgeVlanDevices): array
    {
        $lines = [];
        $rendered = [];

        foreach ($interfaces as $interface) {
            if (($interface['type'] ?? null) !== 'bridge') {
                continue;
            }

            $name = (string) $interface['name'];
            $ports = $interfaces
                ->filter(fn (array $row): bool => (string) ($row['master'] ?? '') === $name)
                ->pluck('name')
                ->map(fn (mixed $port): string => (string) $port)
                ->values()
                ->all();

            $section = $this->uciName("nur_br_{$name}");
            $lines[] = UciSerializer::section('device', $section);
            $lines[] = UciSerializer::entry('option', 'name', $name);
            $lines[] = UciSerializer::entry('option', 'type', 'bridge');

            foreach ($ports as $port) {
                $lines[] = UciSerializer::entry('list', 'ports', $port);
            }

            if ($this->truthy($interface['vlan_filtering'] ?? false) || in_array($name, $bridgeVlanDevices, true)) {
                $lines[] = UciSerializer::entry('option', 'vlan_filtering', '1');
            }

            if (($interface['mtu'] ?? null) !== null && $interface['mtu'] !== '') {
                $lines[] = UciSerializer::entry('option', 'mtu', ((int) $interface['mtu']));
            }

            $lines[] = '';
            $rendered[$name] = true;
        }

        foreach ($bridgeVlanDevices as $bridgeName) {
            if (isset($rendered[$bridgeName])) {
                continue;
            }

            $section = $this->uciName("nur_br_{$bridgeName}");
            $lines[] = UciSerializer::section('device', $section);
            $lines[] = UciSerializer::entry('option', 'name', $bridgeName);
            $lines[] = UciSerializer::entry('option', 'type', 'bridge');
            $lines[] = UciSerializer::entry('option', 'vlan_filtering', '1');
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $interfaces
     * @return list<string>
     */
    private function renderVlanDevices($interfaces): array
    {
        $lines = [];

        foreach ($interfaces as $interface) {
            if (($interface['type'] ?? null) !== 'vlan') {
                continue;
            }

            $name = (string) $interface['name'];
            $parent = (string) ($interface['parent'] ?? '');
            $vid = (int) ($interface['vlan_id'] ?? 0);

            if ($parent === '' || $vid < 1) {
                continue;
            }

            $section = $this->uciName("nur_vlan_{$name}");
            $lines[] = UciSerializer::section('device', $section);
            $lines[] = UciSerializer::entry('option', 'name', $name);
            $lines[] = UciSerializer::entry('option', 'type', '8021q');
            $lines[] = UciSerializer::entry('option', 'ifname', $parent);
            $lines[] = UciSerializer::entry('option', 'vid', $vid);

            if (($interface['mtu'] ?? null) !== null && $interface['mtu'] !== '') {
                $lines[] = UciSerializer::entry('option', 'mtu', ((int) $interface['mtu']));
            }

            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  Collection<int, array<string, mixed>>  $interfaces
     * @return list<string>
     */
    private function renderBridgeVlans(array $configuration, $interfaces): array
    {
        $lines = [];
        $groups = [];
        foreach (Arr::get($configuration, 'bridge_vlans', []) as $vlan) {
            if (empty($vlan['device']) || empty($vlan['vid'])) {
                continue;
            }
            $device = (string) $vlan['device'];
            $vid = (int) $vlan['vid'];
            $key = $device.'|'.$vid;
            $groups[$key] ??= ['device' => $device, 'vid' => $vid, 'ports' => [], 'local' => false];
            $ports = ! empty($vlan['port']) ? [(string) $vlan['port']] : $interfaces->filter(fn (array $row): bool => ($row['master'] ?? '') === $device)->pluck('name')->all();
            $groups[$key]['local'] = $groups[$key]['local'] || $this->truthy($vlan['self'] ?? true);
            $suffix = $this->truthy($vlan['untagged'] ?? false) ? 'u' : 't';
            if ($this->truthy($vlan['pvid'] ?? false)) {
                $suffix .= '*';
            }
            foreach (array_unique($ports) as $port) {
                $groups[$key]['ports'][$port] = $suffix;
            }
        }
        foreach (array_values($groups) as $index => $group) {
            $lines[] = UciSerializer::section('bridge-vlan', "nur_bv_{$index}");
            $lines[] = UciSerializer::entry('option', 'device', $group['device']);
            $lines[] = UciSerializer::entry('option', 'vlan', $group['vid']);
            $lines[] = UciSerializer::entry('option', 'local', ($group['local'] ? '1' : '0'));
            foreach ($group['ports'] as $port => $suffix) {
                $lines[] = UciSerializer::entry('list', 'ports', "{$port}:{$suffix}");
            }
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $interfaces
     * @return array<string, string>
     */
    private function deviceAliases($interfaces): array
    {
        $aliases = [];

        foreach ($interfaces as $interface) {
            $name = (string) ($interface['name'] ?? '');
            $type = (string) ($interface['type'] ?? '');

            if ($name === '') {
                continue;
            }

            if ($type === 'vlan') {
                $parent = (string) ($interface['parent'] ?? '');
                $vid = (int) ($interface['vlan_id'] ?? 0);
                if ($parent !== '' && $vid > 0) {
                    // Prefer the explicit 8021q device name when declared; OpenWrt also accepts parent.vid.
                    $aliases[$name] = $name;
                }
            } elseif ($type === 'bridge') {
                $aliases[$name] = $name;
            }
        }

        return $aliases;
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function renderDhcp(array $configuration): string
    {
        $lines = [
            '# Generated NUR WinControl DHCP and DNS preview',
            "config dnsmasq 'main'",
            "    option domainneeded '1'",
            "    option boguspriv '1'",
            "    option localservice '1'",
        ];

        $forwarders = Arr::get($configuration, 'services.dns.forwarders', []);

        if ($forwarders !== []) {
            $lines[] = UciSerializer::entry('option', 'noresolv', '1');

            foreach ($forwarders as $forwarder) {
                $lines[] = UciSerializer::entry('list', 'server', ((string) $forwarder));
            }
        }

        foreach (Arr::get($configuration, 'services.dns.search_domains', []) as $domain) {
            $lines[] = '    # search domain '.((string) $domain);

        }

        $domains = Arr::get($configuration, 'services.dns.search_domains', []);
        if ($domains !== []) {
            $lines[] = UciSerializer::entry('list', 'dhcp_option', '119,'.implode(',', $domains));
        }

        foreach (Arr::get($configuration, 'services.dhcp_servers', []) as $server) {
            $iface = $this->uciName((string) ($server['interface'] ?? 'lan'));
            $lines[] = '';
            $lines[] = UciSerializer::section('dhcp', $iface);
            $lines[] = UciSerializer::entry('option', 'interface', $iface);
            $lines[] = UciSerializer::entry('option', 'start', ((string) ($server['start'] ?? '100')));
            $lines[] = UciSerializer::entry('option', 'limit', ((string) ($server['limit'] ?? '150')));
            $lines[] = UciSerializer::entry('option', 'leasetime', ((string) ($server['lease_time'] ?? '12h')));

            if (($server['domain'] ?? null) !== null && $server['domain'] !== '') {
                $lines[] = UciSerializer::entry('option', 'domain', ((string) $server['domain']));
            }

            $dnsServers = $server['dns_servers'] ?? [];

            if (is_array($dnsServers) && $dnsServers !== []) {
                $lines[] = UciSerializer::entry('list', 'dhcp_option', '6,'.implode(',', array_map('strval', $dnsServers)).'');
            }
        }

        foreach (Arr::get($configuration, 'services.dhcp_reservations', []) as $index => $reservation) {
            if ($this->truthy($reservation['disabled'] ?? false)) {
                continue;
            }
            $lines[] = UciSerializer::section('host', "nur_reservation_{$index}");
            foreach (['ip' => 'ip', 'mac' => 'mac', 'hostname' => 'name', 'lease_time' => 'leasetime'] as $field => $option) {
                if (! empty($reservation[$field])) {
                    $lines[] = UciSerializer::entry('option', "{$option}", $reservation[$field]);
                }
            }
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function renderFirewall(array $configuration): string
    {
        foreach (['rules', 'nat'] as $list) {
            $configuration['firewall'][$list] = array_values(array_filter(Arr::get($configuration, 'firewall.'.$list, []), fn (array $rule): bool => ! $this->truthy($rule['disabled'] ?? false)));
        }
        $lines = [
            '# Generated NUR WinControl firewall preview',
            'config defaults',
            "    option input 'DROP'",
            "    option output 'ACCEPT'",
            "    option forward 'DROP'",
            "    option synflood_protect '1'",
        ];

        $lanNetworks = collect(Arr::get($configuration, 'services.dhcp_servers', []))
            ->pluck('interface')
            ->filter()
            ->map(fn (mixed $value): string => $this->uciName((string) $value))
            ->unique()
            ->values();
        $managementInterface = (string) Arr::get($configuration, 'management.interface', 'mgmt0');
        $managementNetwork = $this->uciName($managementInterface);
        $addressNetworks = collect(Arr::get($configuration, 'addresses', []))
            ->pluck('interface')
            ->filter()
            ->map(fn (mixed $value): string => $this->uciName((string) $value));
        $staticWanNetworks = collect(Arr::get($configuration, 'firewall.nat', []))
            ->filter(fn (array $rule): bool => in_array($rule['action'] ?? '', ['masquerade', 'snat'], true))
            ->pluck('out_interface')
            ->merge(collect(Arr::get($configuration, 'routes', []))->filter(fn (array $route): bool => in_array($route['destination'] ?? '', ['default', '0.0.0.0/0'], true))->pluck('device'))
            ->filter()
            ->map(fn (mixed $value): string => $this->uciName((string) $value))
            ->filter(fn (string $network): bool => $addressNetworks->contains($network)
                && $network !== $managementNetwork && $network !== 'management'
                && ! $lanNetworks->contains($network));
        $wanNetworks = collect(Arr::get($configuration, 'services.pppoe_clients', []))
            ->pluck('name')
            ->filter()
            ->map(fn (mixed $value): string => $this->uciName((string) $value))
            ->unique()
            ->values();
        $wanNetworks = $wanNetworks->merge($staticWanNetworks)->unique()->values();

        $lanNetworks = $lanNetworks->merge($addressNetworks->reject(fn ($network) => $wanNetworks->contains($network) || in_array($network, [$managementNetwork, 'management'], true)))->unique()->values();

        $zoneDefinitions = [
            'vpn' => collect(Arr::get($configuration, 'services.wireguard.interfaces', []))->pluck('name')->all(),
            'management' => ['management'],
            'lan' => $lanNetworks->all(),
            'wan' => $wanNetworks->all(),
        ];
        $zoneAliases = [
            'vpn' => $zoneDefinitions['vpn'],
            'management' => collect(['management', $managementInterface, $this->uciName($managementInterface)])
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'lan' => collect($lanNetworks->all())
                ->merge(collect(Arr::get($configuration, 'services.dhcp_servers', []))->pluck('interface')->filter()->map(fn ($v) => (string) $v))
                ->unique()
                ->values()
                ->all(),
            'wan' => collect($wanNetworks->all())
                ->merge(collect(Arr::get($configuration, 'services.pppoe_clients', []))->pluck('name')->filter()->map(fn ($v) => (string) $v))
                ->unique()
                ->values()
                ->all(),
        ];

        foreach ($zoneDefinitions as $zoneName => $networks) {
            if ($networks === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = 'config zone';
            $lines[] = UciSerializer::entry('option', 'name', $zoneName);

            foreach ($networks as $network) {
                $lines[] = UciSerializer::entry('list', 'network', $network);
            }

            if ($zoneName === 'management') {
                $lines[] = UciSerializer::entry('option', 'input', 'ACCEPT');
                $lines[] = UciSerializer::entry('option', 'output', 'ACCEPT');
                $lines[] = UciSerializer::entry('option', 'forward', 'DROP');
            } elseif ($zoneName === 'lan') {
                $lines[] = UciSerializer::entry('option', 'input', 'ACCEPT');
                $lines[] = UciSerializer::entry('option', 'output', 'ACCEPT');
                $lines[] = UciSerializer::entry('option', 'forward', 'ACCEPT');
            } else {
                $lines[] = UciSerializer::entry('option', 'input', 'DROP');
                $lines[] = UciSerializer::entry('option', 'output', 'ACCEPT');
                $lines[] = UciSerializer::entry('option', 'forward', 'DROP');
                $lines[] = UciSerializer::entry('option', 'mtu_fix', '1');

                if ($zoneName === 'wan' && collect(Arr::get($configuration, 'firewall.nat', []))
                    ->contains(fn (array $rule): bool => ($rule['action'] ?? null) === 'masquerade' && empty($rule['source']) && empty($rule['destination']) && empty($rule['source_port']) && empty($rule['destination_port']) && empty($rule['to_port']) && in_array($rule['protocol'] ?? '', ['', 'all'], true) && in_array($rule['out_interface'] ?? '', $zoneAliases['wan'], true))) {
                    $lines[] = UciSerializer::entry('option', 'masq', '1');
                }
            }
        }

        if (data_get($configuration, 'services.pppoe_server.enabled', false)) {
            $lines = array_merge($lines, [
                '', "config zone 'nur_bras'", "    option name 'bras'",
                "    list device 'nurbs+'", "    option input 'DROP'",
                "    option output 'ACCEPT'", "    option forward 'DROP'",
            ]);
            $zoneAliases['bras'] = ['bras', 'pppoe_server'];
            if (data_get($configuration, 'services.pppoe_server.auth_mode') === 'radius') {
                $radius = data_get($configuration, 'services.pppoe_server.radius', []);
                foreach (['address', 'dae_address'] as $field) {
                    if (! filter_var($radius[$field] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        throw new RuntimeException('Invalid BRAS RADIUS listener address.');
                    }
                }
                $lines = array_merge($lines, [
                    '', "config rule 'nur_bras_dae'", "    option name 'NUR BRAS authorized RADIUS DAE'",
                    "    option src '*'", UciSerializer::entry('option', 'src_ip', $radius['address']),
                    UciSerializer::entry('option', 'dest_ip', $radius['dae_address']), "    option proto 'udp'",
                    "    option dest_port '3799'", "    option target 'ACCEPT'",
                ]);
            }
        }

        if ($lanNetworks->isNotEmpty() && $wanNetworks->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'config forwarding';
            $lines[] = UciSerializer::entry('option', 'src', 'lan');
            $lines[] = UciSerializer::entry('option', 'dest', 'wan');
        }

        foreach (Arr::get($configuration, 'firewall.rules', []) as $rule) {
            $target = strtoupper((string) ($rule['action'] ?? 'ACCEPT'));
            $srcZone = $this->resolveZoneName((string) ($rule['in_interface'] ?? ''), $zoneAliases);
            $destZone = $this->resolveZoneName((string) ($rule['out_interface'] ?? ''), $zoneAliases);

            $chain = $rule['chain'] ?? 'forward';
            if (! in_array($chain, ['input', 'output', 'forward'], true)) {
                throw new \InvalidArgumentException('Unsupported firewall chain.');
            }
            foreach (['in_interface' => $srcZone, 'out_interface' => $destZone] as $field => $zone) {
                if (! empty($rule[$field]) && $zone === null) {
                    throw new \InvalidArgumentException('Firewall interface has no supported zone: '.$rule[$field]);
                }
            }
            if ($chain === 'input' && ! empty($rule['out_interface'])) {
                throw new \InvalidArgumentException('Input rules cannot select an output interface.');
            }
            if ($chain === 'output' && ! empty($rule['in_interface'])) {
                throw new \InvalidArgumentException('Output rules cannot select an input interface.');
            }
            $srcZone = $chain === 'output' ? null : ($srcZone ?? '*');
            $destZone = $chain === 'input' ? null : ($destZone ?? '*');

            $lines[] = '';
            $lines[] = 'config rule';
            $lines[] = UciSerializer::entry('option', 'name', 'nur-'.strtolower($target).'-'.substr(md5(json_encode($rule)), 0, 8).'');
            $lines[] = UciSerializer::entry('option', 'target', $target);

            if ($srcZone !== null) {
                $lines[] = UciSerializer::entry('option', 'src', $srcZone);
            }

            if ($destZone !== null) {
                $lines[] = UciSerializer::entry('option', 'dest', $destZone);
            }

            if (($rule['protocol'] ?? null) !== null && $rule['protocol'] !== '') {
                $lines[] = UciSerializer::entry('option', 'proto', ((string) $rule['protocol']));
            }

            if (($rule['source'] ?? null) !== null && $rule['source'] !== '') {
                $lines[] = UciSerializer::entry('option', 'src_ip', ((string) $rule['source']));
            }

            if (($rule['destination'] ?? null) !== null && $rule['destination'] !== '') {
                $lines[] = UciSerializer::entry('option', 'dest_ip', ((string) $rule['destination']));
            }
            foreach (['source_port' => 'src_port', 'destination_port' => 'dest_port'] as $key => $option) {
                if (! empty($rule[$key])) {
                    $lines[] = UciSerializer::entry('option', "{$option}", $rule[$key]);
                }
            }
        }

        foreach (Arr::get($configuration, 'firewall.nat', []) as $index => $natRule) {
            $action = strtolower((string) ($natRule['action'] ?? ''));
            $expectedChain = $action === 'dnat' ? 'prerouting' : 'postrouting';
            if (($natRule['chain'] ?? $expectedChain) !== $expectedChain) {
                throw new \InvalidArgumentException('NAT action is incompatible with the requested chain.');
            }

            $restricted = $this->resolveZoneName((string) ($natRule['out_interface'] ?? ''), $zoneAliases) !== 'wan' || ! empty($natRule['source']) || ! empty($natRule['destination']) || ! empty($natRule['source_port']) || ! empty($natRule['destination_port']) || ! empty($natRule['to_port']) || ! in_array($natRule['protocol'] ?? '', ['', 'all'], true);
            if ($action === 'masquerade' && ! $restricted) {
                continue;
            }
            if (in_array($action, ['snat', 'masquerade'], true)) {
                $target = strtoupper($action);
                $lines[] = '';
                $lines[] = UciSerializer::section('nat', "nur_snat_{$index}");
                $lines[] = UciSerializer::entry('option', 'name', "nur-snat-{$index}");
                $lines[] = UciSerializer::entry('option', 'target', $target);
                $zone = $this->resolveZoneName((string) ($natRule['out_interface'] ?? ''), $zoneAliases) ?? 'wan';
                $lines[] = UciSerializer::entry('option', 'src', $zone);
                foreach (['to_address' => 'snat_ip', 'source' => 'src_ip', 'destination' => 'dest_ip', 'protocol' => 'proto', 'source_port' => 'src_port', 'destination_port' => 'dest_port', 'to_port' => 'snat_port'] as $key => $option) {
                    if (! empty($natRule[$key])) {
                        $lines[] = UciSerializer::entry('option', "{$option}", $natRule[$key]);
                    }
                }

                continue;
            }

            if ($action === 'dnat') {
                $lines[] = '';
                $lines[] = UciSerializer::section('redirect', "nur_dnat_{$index}");
                $destinationZone = $this->resolveZoneName((string) ($natRule['out_interface'] ?? ''), $zoneAliases);
                if ($destinationZone !== null) {
                    $lines[] = UciSerializer::entry('option', 'dest', $destinationZone);
                }
                foreach (['source' => 'src_ip', 'source_port' => 'src_port', 'destination_port' => 'src_dport', 'to_port' => 'dest_port'] as $key => $option) {
                    if (! empty($natRule[$key])) {
                        $lines[] = UciSerializer::entry('option', "{$option}", $natRule[$key]);
                    }
                }
                $lines[] = UciSerializer::entry('option', 'name', "nur-dnat-{$index}");
                $lines[] = UciSerializer::entry('option', 'target', 'DNAT');
                $srcZone = $this->resolveZoneName((string) ($natRule['in_interface'] ?? ''), $zoneAliases) ?? 'wan';
                $lines[] = UciSerializer::entry('option', 'src', $srcZone);

                if (($natRule['to_address'] ?? null) !== null && $natRule['to_address'] !== '') {
                    $lines[] = UciSerializer::entry('option', 'dest_ip', ((string) $natRule['to_address']));
                }

                if (($natRule['destination'] ?? null) !== null && $natRule['destination'] !== '') {
                    $lines[] = UciSerializer::entry('option', 'src_dip', ((string) $natRule['destination']));
                }

                if (($natRule['protocol'] ?? null) !== null && $natRule['protocol'] !== '') {
                    $lines[] = UciSerializer::entry('option', 'proto', ((string) $natRule['protocol']));
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array<string, string>
     */
    private function cidrToAddressParts(string $cidr): array
    {
        if ($cidr === '' || ! str_contains($cidr, '/')) {
            return [];
        }

        [$address, $prefix] = explode('/', $cidr, 2);

        return [
            'ipaddr' => $address,
            'netmask' => $this->prefixToNetmask((int) $prefix),
        ];
    }

    private function prefixToNetmask(int $prefix): string
    {
        $mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;

        return implode('.', [
            ($mask >> 24) & 255,
            ($mask >> 16) & 255,
            ($mask >> 8) & 255,
            $mask & 255,
        ]);
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, [1, '1', 'true', 'on', 'yes'], true);
    }

    /**
     * OpenWrt UCI section names must be [A-Za-z0-9_]. Keep Linux device names elsewhere.
     */
    private function uciName(string $name): string
    {
        if (! preg_match('/^[A-Za-z0-9_.-]+$/D', $name)) {
            throw new \InvalidArgumentException('Invalid configuration identifier.');
        }

        $section = str_replace(['-', '.'], '_', $name);
        if (isset($this->sectionSources[$section]) && $this->sectionSources[$section] !== $name) {
            throw new \InvalidArgumentException('Configuration identifiers collide after UCI normalization.');
        }
        $this->sectionSources[$section] = $name;

        return $section;
    }

    /**
     * @param  array<string, array<int, string>>  $zoneNetworks
     */
    private function resolveZoneName(string $interfaceName, array $zoneNetworks): ?string
    {
        if ($interfaceName === '') {
            return null;
        }

        foreach ($zoneNetworks as $zoneName => $networks) {
            if (in_array($interfaceName, $networks, true)) {
                return $zoneName;
            }
        }

        return null;
    }
}
