<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class OpenWrtRuntimeConfigRenderer
{
    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $secretConfiguration
     * @return array<string, string>
     */
    public function render(array $configuration, array $secretConfiguration = [], bool $maskSecrets = true): array
    {
        return [
            'network' => $this->renderNetwork($configuration, $secretConfiguration, $maskSecrets),
            'dhcp' => $this->renderDhcp($configuration),
            'firewall' => $this->renderFirewall($configuration),
            'pppoe_shaping' => $this->renderPppoeShaping($configuration),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
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
                    'name' => $name,
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

        $lines[] = "config interface 'management'";
        $lines[] = "    option proto 'static'";
        $lines[] = "    option device '".($deviceAliases[$managementInterface] ?? $managementInterface)."'";

        foreach ($this->cidrToAddressParts((string) Arr::get($configuration, 'management.cidr', '')) as $key => $value) {
            $lines[] = "    option {$key} '{$value}'";
        }

        $configuredInterfaces = collect(Arr::get($configuration, 'addresses', []))
            ->groupBy('interface')
            ->except([$managementInterface]);

        foreach ($configuredInterfaces as $interfaceName => $interfaceAddresses) {
            /** @var array<string, mixed>|null $primaryAddress */
            $primaryAddress = collect($interfaceAddresses)->first();
            $device = $deviceAliases[(string) $interfaceName] ?? (string) $interfaceName;

            $lines[] = '';
            $lines[] = "config interface '{$interfaceName}'";
            $lines[] = "    option device '{$device}'";
            $lines[] = "    option proto 'static'";

            foreach ($this->cidrToAddressParts((string) ($primaryAddress['cidr'] ?? '')) as $key => $value) {
                $lines[] = "    option {$key} '{$value}'";
            }
        }

        foreach (Arr::get($configuration, 'routes', []) as $index => $route) {
            $lines[] = '';
            $lines[] = "config route 'generated_{$index}'";
            $lines[] = "    option interface '".((string) ($route['device'] ?? 'management'))."'";
            $lines[] = "    option target '".(($route['destination'] ?? null) === 'default' ? '0.0.0.0/0' : (string) ($route['destination'] ?? '0.0.0.0/0'))."'";

            if (($route['gateway'] ?? null) !== null && $route['gateway'] !== '') {
                $lines[] = "    option gateway '".((string) $route['gateway'])."'";
            }

            if (($route['metric'] ?? null) !== null && $route['metric'] !== '') {
                $lines[] = "    option metric '".((string) $route['metric'])."'";
            }
        }

        foreach (Arr::get($configuration, 'services.pppoe_clients', []) as $client) {
            $name = (string) ($client['name'] ?? 'pppoe');
            $password = (string) data_get($secretConfiguration, "services.pppoe_clients.{$name}.password", '');
            $pppoeDevice = (string) ($client['interface'] ?? 'eth0');

            $lines[] = '';
            $lines[] = "config interface '{$name}'";
            $lines[] = "    option proto 'pppoe'";
            $lines[] = "    option device '".($deviceAliases[$pppoeDevice] ?? $pppoeDevice)."'";
            $lines[] = "    option username '".((string) ($client['username'] ?? ''))."'";

            if ($password !== '') {
                $lines[] = "    option password '".($maskSecrets ? '***redacted***' : $password)."'";
            }

            if (($client['service_name'] ?? null) !== null && $client['service_name'] !== '') {
                $lines[] = "    option service '".((string) $client['service_name'])."'";
            }

            if (($client['mtu'] ?? null) !== null && $client['mtu'] !== '') {
                $lines[] = "    option mtu '".((string) $client['mtu'])."'";
            }

            $lines[] = "    option defaultroute '".($this->truthy($client['default_route'] ?? true) ? '1' : '0')."'";

            // Profile rates are applied by nur-pppoe-shape after bring-up; recorded here for operators.
            if ((int) ($client['download_kbps'] ?? 0) > 0) {
                $lines[] = '    # nur_download_kbps '.((int) $client['download_kbps']);
            }
            if ((int) ($client['upload_kbps'] ?? 0) > 0) {
                $lines[] = '    # nur_upload_kbps '.((int) $client['upload_kbps']);
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

            $lines[] = "config device 'nur_br_{$name}'";
            $lines[] = "    option name '{$name}'";
            $lines[] = "    option type 'bridge'";

            foreach ($ports as $port) {
                $lines[] = "    list ports '{$port}'";
            }

            if ($this->truthy($interface['vlan_filtering'] ?? false) || in_array($name, $bridgeVlanDevices, true)) {
                $lines[] = "    option vlan_filtering '1'";
            }

            if (($interface['mtu'] ?? null) !== null && $interface['mtu'] !== '') {
                $lines[] = "    option mtu '".((int) $interface['mtu'])."'";
            }

            $lines[] = '';
            $rendered[$name] = true;
        }

        foreach ($bridgeVlanDevices as $bridgeName) {
            if (isset($rendered[$bridgeName])) {
                continue;
            }

            $lines[] = "config device 'nur_br_{$bridgeName}'";
            $lines[] = "    option name '{$bridgeName}'";
            $lines[] = "    option type 'bridge'";
            $lines[] = "    option vlan_filtering '1'";
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

            $lines[] = "config device 'nur_vlan_{$name}'";
            $lines[] = "    option name '{$name}'";
            $lines[] = "    option type '8021q'";
            $lines[] = "    option ifname '{$parent}'";
            $lines[] = "    option vid '{$vid}'";

            if (($interface['mtu'] ?? null) !== null && $interface['mtu'] !== '') {
                $lines[] = "    option mtu '".((int) $interface['mtu'])."'";
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
        $bridgeVlans = collect(Arr::get($configuration, 'bridge_vlans', []))
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['device'] ?? null) && filled($row['vid'] ?? null));

        foreach ($bridgeVlans as $index => $vlan) {
            $device = (string) $vlan['device'];
            $vid = (int) $vlan['vid'];
            $explicitPort = (string) ($vlan['port'] ?? '');
            $ports = [];

            if ($explicitPort !== '') {
                $ports[] = $explicitPort;
            } else {
                $memberPorts = $interfaces
                    ->filter(fn (array $row): bool => (string) ($row['master'] ?? '') === $device)
                    ->pluck('name')
                    ->map(fn (mixed $port): string => (string) $port)
                    ->values()
                    ->all();

                if ($memberPorts === [] || $this->truthy($vlan['self'] ?? false)) {
                    $ports[] = $device;
                } else {
                    $ports = $memberPorts;
                }
            }

            $suffix = $this->truthy($vlan['untagged'] ?? false) ? 'u' : 't';
            if ($this->truthy($vlan['pvid'] ?? false)) {
                $suffix .= '*';
            }

            $lines[] = "config bridge-vlan 'nur_bv_{$index}'";
            $lines[] = "    option device '{$device}'";
            $lines[] = "    option vlan '{$vid}'";

            foreach ($ports as $port) {
                $lines[] = "    list ports '{$port}:{$suffix}'";
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
            $lines[] = "    option noresolv '1'";

            foreach ($forwarders as $forwarder) {
                $lines[] = "    list server '".((string) $forwarder)."'";
            }
        }

        foreach (Arr::get($configuration, 'services.dns.search_domains', []) as $domain) {
            $lines[] = '    # search domain '.((string) $domain);
        }

        foreach (Arr::get($configuration, 'services.dhcp_servers', []) as $server) {
            $lines[] = '';
            $lines[] = "config dhcp '".((string) ($server['interface'] ?? 'lan'))."'";
            $lines[] = "    option interface '".((string) ($server['interface'] ?? 'lan'))."'";
            $lines[] = "    option start '".((string) ($server['start'] ?? '100'))."'";
            $lines[] = "    option limit '".((string) ($server['limit'] ?? '150'))."'";
            $lines[] = "    option leasetime '".((string) ($server['lease_time'] ?? '12h'))."'";

            if (($server['domain'] ?? null) !== null && $server['domain'] !== '') {
                $lines[] = "    option domain '".((string) $server['domain'])."'";
            }

            $dnsServers = $server['dns_servers'] ?? [];

            if (is_array($dnsServers) && $dnsServers !== []) {
                $lines[] = "    list dhcp_option '6,".implode(',', array_map('strval', $dnsServers))."'";
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function renderFirewall(array $configuration): string
    {
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
            ->map(fn (mixed $value): string => (string) $value)
            ->unique()
            ->values();
        $wanNetworks = collect(Arr::get($configuration, 'services.pppoe_clients', []))
            ->pluck('name')
            ->filter()
            ->map(fn (mixed $value): string => (string) $value)
            ->unique()
            ->values();
        $managementInterface = (string) Arr::get($configuration, 'management.interface', 'mgmt0');

        $zoneDefinitions = [
            'management' => ['management'],
            'lan' => $lanNetworks->all(),
            'wan' => $wanNetworks->all(),
        ];
        $zoneAliases = [
            'management' => collect(['management', $managementInterface])
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'lan' => $lanNetworks->all(),
            'wan' => $wanNetworks->all(),
        ];

        foreach ($zoneDefinitions as $zoneName => $networks) {
            if ($networks === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = 'config zone';
            $lines[] = "    option name '{$zoneName}'";

            foreach ($networks as $network) {
                $lines[] = "    list network '{$network}'";
            }

            if ($zoneName === 'management') {
                $lines[] = "    option input 'ACCEPT'";
                $lines[] = "    option output 'ACCEPT'";
                $lines[] = "    option forward 'DROP'";
            } elseif ($zoneName === 'lan') {
                $lines[] = "    option input 'ACCEPT'";
                $lines[] = "    option output 'ACCEPT'";
                $lines[] = "    option forward 'ACCEPT'";
            } else {
                $lines[] = "    option input 'DROP'";
                $lines[] = "    option output 'ACCEPT'";
                $lines[] = "    option forward 'DROP'";
                $lines[] = "    option mtu_fix '1'";

                if (collect(Arr::get($configuration, 'firewall.nat', []))
                    ->contains(fn (array $rule): bool => ($rule['action'] ?? null) === 'masquerade')) {
                    $lines[] = "    option masq '1'";
                }
            }
        }

        if ($lanNetworks->isNotEmpty() && $wanNetworks->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'config forwarding';
            $lines[] = "    option src 'lan'";
            $lines[] = "    option dest 'wan'";
        }

        foreach (Arr::get($configuration, 'firewall.rules', []) as $rule) {
            $target = strtoupper((string) ($rule['action'] ?? 'ACCEPT'));
            $srcZone = $this->resolveZoneName((string) ($rule['in_interface'] ?? ''), $zoneAliases);
            $destZone = $this->resolveZoneName((string) ($rule['out_interface'] ?? ''), $zoneAliases);

            $lines[] = '';
            $lines[] = 'config rule';
            $lines[] = "    option name 'nur-".strtolower($target).'-'.substr(md5(json_encode($rule)), 0, 8)."'";
            $lines[] = "    option target '{$target}'";

            if ($srcZone !== null) {
                $lines[] = "    option src '{$srcZone}'";
            }

            if ($destZone !== null) {
                $lines[] = "    option dest '{$destZone}'";
            }

            if (($rule['protocol'] ?? null) !== null && $rule['protocol'] !== '') {
                $lines[] = "    option proto '".((string) $rule['protocol'])."'";
            }

            if (($rule['source'] ?? null) !== null && $rule['source'] !== '') {
                $lines[] = "    option src_ip '".((string) $rule['source'])."'";
            }

            if (($rule['destination'] ?? null) !== null && $rule['destination'] !== '') {
                $lines[] = "    option dest_ip '".((string) $rule['destination'])."'";
            }
        }

        foreach (Arr::get($configuration, 'firewall.nat', []) as $index => $natRule) {
            $action = strtolower((string) ($natRule['action'] ?? ''));

            if ($action === 'masquerade') {
                // Zone masq option above covers masquerade; skip duplicate UCI nat section.
                continue;
            }

            if ($action === 'snat') {
                $lines[] = '';
                $lines[] = "config nat 'nur_snat_{$index}'";
                $lines[] = "    option name 'nur-snat-{$index}'";
                $lines[] = "    option target 'SNAT'";
                $srcZone = $this->resolveZoneName((string) ($natRule['out_interface'] ?? ''), $zoneAliases) ?? 'wan';
                $lines[] = "    option src '{$srcZone}'";

                if (($natRule['to_address'] ?? null) !== null && $natRule['to_address'] !== '') {
                    $lines[] = "    option snat_ip '".((string) $natRule['to_address'])."'";
                }

                if (($natRule['source'] ?? null) !== null && $natRule['source'] !== '') {
                    $lines[] = "    option src_ip '".((string) $natRule['source'])."'";
                }

                if (($natRule['protocol'] ?? null) !== null && $natRule['protocol'] !== '') {
                    $lines[] = "    option proto '".((string) $natRule['protocol'])."'";
                }

                continue;
            }

            if ($action === 'dnat') {
                $lines[] = '';
                $lines[] = "config redirect 'nur_dnat_{$index}'";
                $lines[] = "    option name 'nur-dnat-{$index}'";
                $lines[] = "    option target 'DNAT'";
                $srcZone = $this->resolveZoneName((string) ($natRule['in_interface'] ?? ''), $zoneAliases) ?? 'wan';
                $lines[] = "    option src '{$srcZone}'";

                if (($natRule['to_address'] ?? null) !== null && $natRule['to_address'] !== '') {
                    $lines[] = "    option dest_ip '".((string) $natRule['to_address'])."'";
                }

                if (($natRule['destination'] ?? null) !== null && $natRule['destination'] !== '') {
                    $lines[] = "    option src_dip '".((string) $natRule['destination'])."'";
                }

                if (($natRule['protocol'] ?? null) !== null && $natRule['protocol'] !== '') {
                    $lines[] = "    option proto '".((string) $natRule['protocol'])."'";
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
