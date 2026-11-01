<?php

namespace App\Http\Requests;

use App\Models\RouterNode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateRouterDesiredStateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $services = is_array($this->input('services')) ? $this->input('services') : [];
        $dns = is_array(data_get($services, 'dns')) ? data_get($services, 'dns') : [];

        $this->merge([
            'interfaces' => $this->filterBlankRows($this->input('interfaces')),
            'addresses' => $this->filterBlankRows($this->input('addresses')),
            'routes' => $this->filterBlankRows($this->input('routes')),
            'bridge_vlans' => $this->filterBlankRows($this->input('bridge_vlans')),
            'services' => [
                'dns' => [
                    'forwarders' => $this->filterBlankValues(data_get($dns, 'forwarders')),
                    'search_domains' => $this->filterBlankValues(data_get($dns, 'search_domains')),
                ],
                'dhcp_servers' => $this->sanitizeDhcpServers(data_get($services, 'dhcp_servers')),
                'pppoe_clients' => $this->filterBlankRows(data_get($services, 'pppoe_clients')),
            ],
            'firewall' => [
                'rules' => $this->filterBlankRows($this->input('firewall.rules')),
                'nat' => $this->filterBlankRows($this->input('firewall.nat')),
            ],
        ]);
    }

    public function authorize(): bool
    {
        /** @var RouterNode $routerNode */
        $routerNode = $this->route('router');

        return $this->user()?->can('update', $routerNode) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'interfaces' => ['sometimes', 'array'],
            'interfaces.*.name' => ['required_with:interfaces', 'string', 'max:15', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'interfaces.*.type' => ['nullable', 'string', 'in:bridge,veth,vlan,dummy,ether,management'],
            'interfaces.*.state' => ['nullable', 'string', 'in:up,down'],
            'interfaces.*.master' => ['nullable', 'string', 'max:15', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'interfaces.*.parent' => ['nullable', 'string', 'max:15', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'interfaces.*.vlan_id' => ['nullable', 'integer', 'between:1,4094'],
            'interfaces.*.mtu' => ['nullable', 'integer', 'between:576,9216'],
            'addresses' => ['sometimes', 'array'],
            'addresses.*.interface' => ['required_with:addresses', 'string', 'max:15', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'addresses.*.cidr' => ['required_with:addresses', 'string', 'regex:/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/'],
            'routes' => ['sometimes', 'array'],
            'routes.*.destination' => ['required_with:routes', 'string'],
            'routes.*.gateway' => ['nullable', 'ip'],
            'routes.*.device' => ['nullable', 'string', 'max:15', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'routes.*.metric' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'routes.*.protocol' => ['nullable', 'string', 'max:32'],
            'bridge_vlans' => ['sometimes', 'array'],
            'bridge_vlans.*.device' => ['required_with:bridge_vlans', 'string', 'max:15', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'bridge_vlans.*.vid' => ['required_with:bridge_vlans', 'integer', 'between:1,4094'],
            'bridge_vlans.*.pvid' => ['sometimes', 'boolean'],
            'bridge_vlans.*.untagged' => ['sometimes', 'boolean'],
            'bridge_vlans.*.self' => ['sometimes', 'boolean'],
            'forwarding' => ['sometimes', 'array'],
            'forwarding.ipv4' => ['sometimes', 'boolean'],
            'services' => ['sometimes', 'array'],
            'services.dns' => ['sometimes', 'array'],
            'services.dns.forwarders' => ['sometimes', 'array'],
            'services.dns.forwarders.*' => ['required_with:services.dns.forwarders', 'ip'],
            'services.dns.search_domains' => ['sometimes', 'array'],
            'services.dns.search_domains.*' => ['required_with:services.dns.search_domains', 'string', 'max:255', 'regex:/^[a-zA-Z0-9.-]+$/'],
            'services.dhcp_servers' => ['sometimes', 'array'],
            'services.dhcp_servers.*.interface' => ['required_with:services.dhcp_servers', 'string', 'max:15', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'services.dhcp_servers.*.start' => ['required_with:services.dhcp_servers', 'integer', 'min:1', 'max:65535'],
            'services.dhcp_servers.*.limit' => ['required_with:services.dhcp_servers', 'integer', 'min:1', 'max:65535'],
            'services.dhcp_servers.*.lease_time' => ['required_with:services.dhcp_servers', 'string', 'max:32', 'regex:/^[0-9]+[smhdw]?$/'],
            'services.dhcp_servers.*.domain' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9.-]+$/'],
            'services.dhcp_servers.*.dns_servers' => ['sometimes', 'array'],
            'services.dhcp_servers.*.dns_servers.*' => ['required_with:services.dhcp_servers.*.dns_servers', 'ip'],
            'services.pppoe_clients' => ['sometimes', 'array'],
            'services.pppoe_clients.*.name' => ['required_with:services.pppoe_clients', 'string', 'max:32', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'services.pppoe_clients.*.interface' => ['required_with:services.pppoe_clients', 'string', 'max:15', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'services.pppoe_clients.*.username' => ['required_with:services.pppoe_clients', 'string', 'max:255'],
            'services.pppoe_clients.*.password' => ['nullable', 'string', 'max:255'],
            'services.pppoe_clients.*.service_name' => ['nullable', 'string', 'max:255'],
            'services.pppoe_clients.*.default_route' => ['sometimes', 'boolean'],
            'services.pppoe_clients.*.mtu' => ['nullable', 'integer', 'between:576,1492'],
            'services.pppoe_clients.*.download_kbps' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'services.pppoe_clients.*.upload_kbps' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'firewall' => ['sometimes', 'array'],
            'firewall.rules' => ['sometimes', 'array'],
            'firewall.rules.*.chain' => ['required_with:firewall.rules', 'string', 'max:32'],
            'firewall.rules.*.action' => ['required_with:firewall.rules', 'string', 'in:accept,drop,reject'],
            'firewall.rules.*.protocol' => ['nullable', 'string', 'in:tcp,udp,icmp'],
            'firewall.rules.*.source' => ['nullable', 'string'],
            'firewall.rules.*.destination' => ['nullable', 'string'],
            'firewall.rules.*.in_interface' => ['nullable', 'string', 'max:15', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'firewall.rules.*.out_interface' => ['nullable', 'string', 'max:15', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'firewall.nat' => ['sometimes', 'array'],
            'firewall.nat.*.chain' => ['required_with:firewall.nat', 'string', 'max:32'],
            'firewall.nat.*.action' => ['required_with:firewall.nat', 'string', 'in:masquerade,snat,dnat'],
            'firewall.nat.*.protocol' => ['nullable', 'string', 'in:tcp,udp,icmp'],
            'firewall.nat.*.to_address' => ['nullable', 'ip'],
            'firewall.nat.*.source' => ['nullable', 'string'],
            'firewall.nat.*.destination' => ['nullable', 'string'],
            'firewall.nat.*.in_interface' => ['nullable', 'string', 'max:15', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'firewall.nat.*.out_interface' => ['nullable', 'string', 'max:15', 'regex:/^[a-zA-Z0-9._-]+$/'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicConfiguration(): array
    {
        $validated = $this->validated();
        $pppoeClients = array_map(
            fn (array $client): array => collect($client)
                ->except(['password'])
                ->all(),
            data_get($validated, 'services.pppoe_clients', []),
        );

        return [
            'interfaces' => array_values($validated['interfaces'] ?? []),
            'addresses' => array_values($validated['addresses'] ?? []),
            'routes' => array_values($validated['routes'] ?? []),
            'bridge_vlans' => array_values($validated['bridge_vlans'] ?? []),
            'forwarding' => $validated['forwarding'] ?? [],
            'services' => [
                'dns' => [
                    'forwarders' => array_values(data_get($validated, 'services.dns.forwarders', [])),
                    'search_domains' => array_values(data_get($validated, 'services.dns.search_domains', [])),
                ],
                'dhcp_servers' => array_values(data_get($validated, 'services.dhcp_servers', [])),
                'pppoe_clients' => array_values($pppoeClients),
            ],
            'firewall' => $validated['firewall'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function secretConfiguration(): array
    {
        $validated = $this->validated();
        $pppoeSecrets = [];

        foreach (data_get($validated, 'services.pppoe_clients', []) as $client) {
            $name = (string) ($client['name'] ?? '');
            $password = (string) ($client['password'] ?? '');

            if ($name === '' || $password === '') {
                continue;
            }

            $pppoeSecrets[$name] = ['password' => $password];
        }

        return [
            'services' => [
                'pppoe_clients' => $pppoeSecrets,
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->input('addresses', []) as $index => $address) {
                    $cidr = (string) ($address['cidr'] ?? '');

                    if ($cidr === '' || ! $this->isValidIpv4Cidr($cidr)) {
                        $validator->errors()->add("addresses.{$index}.cidr", 'Use a valid IPv4 CIDR such as 192.0.2.10/24.');
                    }
                }

                foreach ($this->input('routes', []) as $index => $route) {
                    $destination = (string) ($route['destination'] ?? '');

                    if ($destination !== 'default' && ! $this->isValidIpv4Cidr($destination)) {
                        $validator->errors()->add("routes.{$index}.destination", 'Use default or an IPv4 CIDR such as 198.51.100.0/24.');
                    }
                }

                foreach ($this->input('services.dhcp_servers', []) as $index => $server) {
                    $start = (int) ($server['start'] ?? 0);
                    $limit = (int) ($server['limit'] ?? 0);

                    if ($start > 0 && $limit > 0 && ($start + $limit - 1) > 65535) {
                        $validator->errors()->add(
                            "services.dhcp_servers.{$index}.limit",
                            'The DHCP range must stay within the available host address space.',
                        );
                    }
                }
            },
        ];
    }

    private function isValidIpv4Cidr(string $cidr): bool
    {
        if (! preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $cidr)) {
            return false;
        }

        [$address, $prefix] = explode('/', $cidr);

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && (int) $prefix >= 0
            && (int) $prefix <= 32;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function filterBlankRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(
            $rows,
            fn (mixed $row): bool => is_array($row) && $this->rowHasContent($row),
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowHasContent(array $row): bool
    {
        foreach ($row as $value) {
            if (is_array($value) && $this->rowHasContent($value)) {
                return true;
            }

            if (is_bool($value)) {
                if ($value) {
                    return true;
                }

                continue;
            }

            if (is_numeric($value)) {
                if ((string) $value !== '0') {
                    return true;
                }

                continue;
            }

            if (is_string($value) && trim($value) !== '' && $value !== '0') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function filterBlankValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                fn (mixed $value): string => is_string($value) ? trim($value) : '',
                $values,
            ),
            fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeDhcpServers(mixed $rows): array
    {
        return array_map(
            function (array $server): array {
                $server['dns_servers'] = $this->filterBlankValues($server['dns_servers'] ?? []);

                return $server;
            },
            $this->filterBlankRows($rows),
        );
    }
}
