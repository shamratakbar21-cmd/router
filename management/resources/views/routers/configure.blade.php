@extends('layouts.app')

@section('content')
    @php
        $desiredConfiguration = $routerNode->desired_configuration ?? [];

        $interfaces = old('interfaces', $desiredConfiguration['interfaces'] ?? []);
        $interfaces[] = [];

        $addresses = old('addresses', $desiredConfiguration['addresses'] ?? []);
        $addresses[] = [];

        $routes = old('routes', $desiredConfiguration['routes'] ?? []);
        $routes[] = [];

        $bridgeVlans = old('bridge_vlans', $desiredConfiguration['bridge_vlans'] ?? []);
        $bridgeVlans[] = [];

        $dnsForwarders = old('services.dns.forwarders', data_get($desiredConfiguration, 'services.dns.forwarders', []));
        $dnsForwarders[] = '';

        $dnsSearchDomains = old('services.dns.search_domains', data_get($desiredConfiguration, 'services.dns.search_domains', []));
        $dnsSearchDomains[] = '';

        $dhcpServers = old('services.dhcp_servers', data_get($desiredConfiguration, 'services.dhcp_servers', []));
        $dhcpServers[] = [];

        $pppoeClients = old('services.pppoe_clients', data_get($desiredConfiguration, 'services.pppoe_clients', []));
        $pppoeClients[] = [];

        $firewallRules = old('firewall.rules', data_get($desiredConfiguration, 'firewall.rules', []));
        $firewallRules[] = [];

        $natRules = old('firewall.nat', data_get($desiredConfiguration, 'firewall.nat', []));
        $natRules[] = [];

        $forwardingIpv4 = old('forwarding.ipv4', data_get($desiredConfiguration, 'forwarding.ipv4', false));
    @endphp

    <div class="card">
        <div style="display: flex; justify-content: space-between; gap: 1rem; align-items: center; flex-wrap: wrap;">
            <div>
                <h1>Configure {{ $routerNode->name }}</h1>
                <p class="muted">
                    Namespace {{ $routerNode->namespace_name }}
                    | Management {{ $routerNode->management_interface }} {{ $routerNode->management_cidr }}
                    | Drift entries {{ count($routerState['diff']['desired_vs_applied']) }}
                </p>
                <p class="muted">Leave unused rows blank. Management interface and CIDR stay in the router settings form.</p>
            </div>
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <a class="button button-secondary" href="{{ route('routers.show', $routerNode) }}">Back to router</a>
                <a class="button button-secondary" href="{{ route('routers.state.show', $routerNode) }}?refresh=1">Refresh observed state</a>
                <a class="button button-secondary" href="{{ route('routers.desired-state.show', $routerNode) }}">Desired state API</a>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('routers.desired-state.update', $routerNode) }}" class="stack">
        @csrf
        @method('PUT')

        <div class="card">
            <h2>Forwarding</h2>
            <label style="display: flex; align-items: center; gap: 0.75rem;">
                <input type="hidden" name="forwarding[ipv4]" value="0">
                <input type="checkbox" name="forwarding[ipv4]" value="1" style="width: auto;" @checked((bool) $forwardingIpv4)>
                <span>Enable IPv4 forwarding</span>
            </label>
            @error('forwarding.ipv4') <div class="error">{{ $message }}</div> @enderror
        </div>

        <div class="card">
            <h2>DNS Forwarders</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Forwarder</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($dnsForwarders as $index => $forwarder)
                        <tr>
                            <td>
                                <input name="services[dns][forwarders][{{ $index }}]" value="{{ $forwarder }}">
                                @error("services.dns.forwarders.$index") <div class="error">{{ $message }}</div> @enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <h3>Search Domains</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Domain</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($dnsSearchDomains as $index => $domain)
                        <tr>
                            <td>
                                <input name="services[dns][search_domains][{{ $index }}]" value="{{ $domain }}">
                                @error("services.dns.search_domains.$index") <div class="error">{{ $message }}</div> @enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>DHCP Servers</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Interface</th>
                        <th>Start</th>
                        <th>Limit</th>
                        <th>Lease Time</th>
                        <th>Domain</th>
                        <th>DNS Servers</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($dhcpServers as $index => $server)
                        <tr>
                            <td>
                                <input name="services[dhcp_servers][{{ $index }}][interface]" value="{{ data_get($server, 'interface', '') }}">
                                @error("services.dhcp_servers.$index.interface") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input type="number" min="1" max="65535" name="services[dhcp_servers][{{ $index }}][start]" value="{{ data_get($server, 'start', '') }}">
                                @error("services.dhcp_servers.$index.start") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input type="number" min="1" max="65535" name="services[dhcp_servers][{{ $index }}][limit]" value="{{ data_get($server, 'limit', '') }}">
                                @error("services.dhcp_servers.$index.limit") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="services[dhcp_servers][{{ $index }}][lease_time]" value="{{ data_get($server, 'lease_time', '') }}">
                                @error("services.dhcp_servers.$index.lease_time") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="services[dhcp_servers][{{ $index }}][domain]" value="{{ data_get($server, 'domain', '') }}">
                                @error("services.dhcp_servers.$index.domain") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <div class="stack">
                                    <input
                                        name="services[dhcp_servers][{{ $index }}][dns_servers][0]"
                                        value="{{ data_get($server, 'dns_servers.0', '') }}"
                                        placeholder="Primary DNS"
                                    >
                                    <input
                                        name="services[dhcp_servers][{{ $index }}][dns_servers][1]"
                                        value="{{ data_get($server, 'dns_servers.1', '') }}"
                                        placeholder="Secondary DNS"
                                    >
                                </div>
                                @error("services.dhcp_servers.$index.dns_servers.0") <div class="error">{{ $message }}</div> @enderror
                                @error("services.dhcp_servers.$index.dns_servers.1") <div class="error">{{ $message }}</div> @enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>PPPoE Clients</h2>
            <p class="muted">Passwords are stored in encrypted secret configuration and rendered redacted in previews.</p>
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Interface</th>
                        <th>Username</th>
                        <th>Password</th>
                        <th>Service</th>
                        <th>Default Route</th>
                        <th>MTU</th>
                        <th>Download kbps</th>
                        <th>Upload kbps</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pppoeClients as $index => $client)
                        <tr>
                            <td>
                                <input name="services[pppoe_clients][{{ $index }}][name]" value="{{ data_get($client, 'name', '') }}">
                                @error("services.pppoe_clients.$index.name") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="services[pppoe_clients][{{ $index }}][interface]" value="{{ data_get($client, 'interface', '') }}">
                                @error("services.pppoe_clients.$index.interface") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="services[pppoe_clients][{{ $index }}][username]" value="{{ data_get($client, 'username', '') }}">
                                @error("services.pppoe_clients.$index.username") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <select name="services[pppoe_clients][{{ $index }}][password_action]"><option value="retain">Retain password</option><option value="replace">Replace password</option><option value="clear">Clear password</option></select>
                                <input type="password" name="services[pppoe_clients][{{ $index }}][password]" value="" autocomplete="new-password">
                                @error("services.pppoe_clients.$index.password") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="services[pppoe_clients][{{ $index }}][service_name]" value="{{ data_get($client, 'service_name', '') }}">
                                @error("services.pppoe_clients.$index.service_name") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input type="hidden" name="services[pppoe_clients][{{ $index }}][default_route]" value="0">
                                <input type="checkbox" name="services[pppoe_clients][{{ $index }}][default_route]" value="1" style="width: auto;" @checked((bool) data_get($client, 'default_route', false))>
                            </td>
                            <td>
                                <input type="number" min="576" max="1492" name="services[pppoe_clients][{{ $index }}][mtu]" value="{{ data_get($client, 'mtu', '') }}">
                                @error("services.pppoe_clients.$index.mtu") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input type="number" min="0" name="services[pppoe_clients][{{ $index }}][download_kbps]" value="{{ data_get($client, 'download_kbps', '') }}" placeholder="kbps">
                                @error("services.pppoe_clients.$index.download_kbps") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input type="number" min="0" name="services[pppoe_clients][{{ $index }}][upload_kbps]" value="{{ data_get($client, 'upload_kbps', '') }}" placeholder="kbps">
                                @error("services.pppoe_clients.$index.upload_kbps") <div class="error">{{ $message }}</div> @enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Interfaces</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>State</th>
                        <th>Parent</th>
                        <th>Master</th>
                        <th>VLAN ID</th>
                        <th>MTU</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($interfaces as $index => $interface)
                        <tr>
                            <td>
                                <input name="interfaces[{{ $index }}][name]" value="{{ data_get($interface, 'name', '') }}">
                                @error("interfaces.$index.name") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <select name="interfaces[{{ $index }}][type]">
                                    <option value="">Select</option>
                                    @foreach (['bridge', 'veth', 'vlan', 'dummy', 'ether'] as $type)
                                        <option value="{{ $type }}" @selected(data_get($interface, 'type') === $type)>{{ $type }}</option>
                                    @endforeach
                                </select>
                                @error("interfaces.$index.type") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <select name="interfaces[{{ $index }}][state]">
                                    <option value="">Default</option>
                                    @foreach (['up', 'down'] as $state)
                                        <option value="{{ $state }}" @selected(data_get($interface, 'state') === $state)>{{ $state }}</option>
                                    @endforeach
                                </select>
                                @error("interfaces.$index.state") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="interfaces[{{ $index }}][parent]" value="{{ data_get($interface, 'parent', '') }}">
                                @error("interfaces.$index.parent") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="interfaces[{{ $index }}][master]" value="{{ data_get($interface, 'master', '') }}">
                                @error("interfaces.$index.master") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input type="number" min="1" max="4094" name="interfaces[{{ $index }}][vlan_id]" value="{{ data_get($interface, 'vlan_id', '') }}">
                                @error("interfaces.$index.vlan_id") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input type="number" min="576" max="9216" name="interfaces[{{ $index }}][mtu]" value="{{ data_get($interface, 'mtu', '') }}">
                                @error("interfaces.$index.mtu") <div class="error">{{ $message }}</div> @enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Addresses</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Interface</th>
                        <th>CIDR</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($addresses as $index => $address)
                        <tr>
                            <td>
                                <input name="addresses[{{ $index }}][interface]" value="{{ data_get($address, 'interface', '') }}">
                                @error("addresses.$index.interface") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="addresses[{{ $index }}][cidr]" value="{{ data_get($address, 'cidr', '') }}">
                                @error("addresses.$index.cidr") <div class="error">{{ $message }}</div> @enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Routes</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Destination</th>
                        <th>Gateway</th>
                        <th>Device</th>
                        <th>Metric</th>
                        <th>Protocol</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($routes as $index => $route)
                        <tr>
                            <td>
                                <input name="routes[{{ $index }}][destination]" value="{{ data_get($route, 'destination', '') }}">
                                @error("routes.$index.destination") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="routes[{{ $index }}][gateway]" value="{{ data_get($route, 'gateway', '') }}">
                                @error("routes.$index.gateway") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="routes[{{ $index }}][device]" value="{{ data_get($route, 'device', '') }}">
                                @error("routes.$index.device") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input type="number" min="0" max="65535" name="routes[{{ $index }}][metric]" value="{{ data_get($route, 'metric', '') }}">
                                @error("routes.$index.metric") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="routes[{{ $index }}][protocol]" value="{{ data_get($route, 'protocol', '') }}">
                                @error("routes.$index.protocol") <div class="error">{{ $message }}</div> @enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Bridge VLANs</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Device</th>
                        <th>VID</th>
                        <th>PVID</th>
                        <th>Untagged</th>
                        <th>Self</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bridgeVlans as $index => $bridgeVlan)
                        <tr>
                            <td>
                                <input name="bridge_vlans[{{ $index }}][device]" value="{{ data_get($bridgeVlan, 'device', '') }}">
                                @error("bridge_vlans.$index.device") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input type="number" min="1" max="4094" name="bridge_vlans[{{ $index }}][vid]" value="{{ data_get($bridgeVlan, 'vid', '') }}">
                                @error("bridge_vlans.$index.vid") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input type="hidden" name="bridge_vlans[{{ $index }}][pvid]" value="0">
                                <input type="checkbox" name="bridge_vlans[{{ $index }}][pvid]" value="1" style="width: auto;" @checked((bool) data_get($bridgeVlan, 'pvid', false))>
                            </td>
                            <td>
                                <input type="hidden" name="bridge_vlans[{{ $index }}][untagged]" value="0">
                                <input type="checkbox" name="bridge_vlans[{{ $index }}][untagged]" value="1" style="width: auto;" @checked((bool) data_get($bridgeVlan, 'untagged', false))>
                            </td>
                            <td>
                                <input type="hidden" name="bridge_vlans[{{ $index }}][self]" value="0">
                                <input type="checkbox" name="bridge_vlans[{{ $index }}][self]" value="1" style="width: auto;" @checked((bool) data_get($bridgeVlan, 'self', false))>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Firewall Filter Rules</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Chain</th>
                        <th>Action</th>
                        <th>Protocol</th>
                        <th>Source</th>
                        <th>Destination</th>
                        <th>Input</th>
                        <th>Output</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($firewallRules as $index => $rule)
                        <tr>
                            <td>
                                <input name="firewall[rules][{{ $index }}][chain]" value="{{ data_get($rule, 'chain', '') }}">
                                @error("firewall.rules.$index.chain") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <select name="firewall[rules][{{ $index }}][action]">
                                    <option value="">Select</option>
                                    @foreach (['accept', 'drop', 'reject'] as $action)
                                        <option value="{{ $action }}" @selected(data_get($rule, 'action') === $action)>{{ $action }}</option>
                                    @endforeach
                                </select>
                                @error("firewall.rules.$index.action") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <select name="firewall[rules][{{ $index }}][protocol]">
                                    <option value="">Any</option>
                                    @foreach (['tcp', 'udp', 'icmp'] as $protocol)
                                        <option value="{{ $protocol }}" @selected(data_get($rule, 'protocol') === $protocol)>{{ $protocol }}</option>
                                    @endforeach
                                </select>
                                @error("firewall.rules.$index.protocol") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="firewall[rules][{{ $index }}][source]" value="{{ data_get($rule, 'source', '') }}">
                                @error("firewall.rules.$index.source") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="firewall[rules][{{ $index }}][destination]" value="{{ data_get($rule, 'destination', '') }}">
                                @error("firewall.rules.$index.destination") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="firewall[rules][{{ $index }}][in_interface]" value="{{ data_get($rule, 'in_interface', '') }}">
                                @error("firewall.rules.$index.in_interface") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="firewall[rules][{{ $index }}][out_interface]" value="{{ data_get($rule, 'out_interface', '') }}">
                                @error("firewall.rules.$index.out_interface") <div class="error">{{ $message }}</div> @enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>NAT Rules</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Chain</th>
                        <th>Action</th>
                        <th>Protocol</th>
                        <th>To Address</th>
                        <th>Source</th>
                        <th>Destination</th>
                        <th>Input</th>
                        <th>Output</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($natRules as $index => $rule)
                        <tr>
                            <td>
                                <input name="firewall[nat][{{ $index }}][chain]" value="{{ data_get($rule, 'chain', '') }}">
                                @error("firewall.nat.$index.chain") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <select name="firewall[nat][{{ $index }}][action]">
                                    <option value="">Select</option>
                                    @foreach (['masquerade', 'snat', 'dnat'] as $action)
                                        <option value="{{ $action }}" @selected(data_get($rule, 'action') === $action)>{{ $action }}</option>
                                    @endforeach
                                </select>
                                @error("firewall.nat.$index.action") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <select name="firewall[nat][{{ $index }}][protocol]">
                                    <option value="">Any</option>
                                    @foreach (['tcp', 'udp', 'icmp'] as $protocol)
                                        <option value="{{ $protocol }}" @selected(data_get($rule, 'protocol') === $protocol)>{{ $protocol }}</option>
                                    @endforeach
                                </select>
                                @error("firewall.nat.$index.protocol") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="firewall[nat][{{ $index }}][to_address]" value="{{ data_get($rule, 'to_address', '') }}">
                                @error("firewall.nat.$index.to_address") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="firewall[nat][{{ $index }}][source]" value="{{ data_get($rule, 'source', '') }}">
                                @error("firewall.nat.$index.source") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="firewall[nat][{{ $index }}][destination]" value="{{ data_get($rule, 'destination', '') }}">
                                @error("firewall.nat.$index.destination") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="firewall[nat][{{ $index }}][in_interface]" value="{{ data_get($rule, 'in_interface', '') }}">
                                @error("firewall.nat.$index.in_interface") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input name="firewall[nat][{{ $index }}][out_interface]" value="{{ data_get($rule, 'out_interface', '') }}">
                                @error("firewall.nat.$index.out_interface") <div class="error">{{ $message }}</div> @enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="card">
            <button type="submit" class="button">Stage desired routing revision</button>
        </div>
    </form>

    <div class="grid">
        <div class="card">
            <h2>Desired OpenWrt Network Preview</h2>
            <pre>{{ $routerState['desired']['runtime_preview']['network'] }}</pre>
        </div>
        <div class="card">
            <h2>Desired OpenWrt DHCP and DNS Preview</h2>
            <pre>{{ $routerState['desired']['runtime_preview']['dhcp'] }}</pre>
        </div>
    </div>
@endsection
