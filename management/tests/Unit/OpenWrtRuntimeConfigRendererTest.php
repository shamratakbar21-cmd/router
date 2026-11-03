<?php

namespace Tests\Unit;

use App\Services\OpenWrtRuntimeConfigRenderer;
use PHPUnit\Framework\TestCase;

class OpenWrtRuntimeConfigRendererTest extends TestCase
{
    public function test_it_rejects_normalization_collisions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OpenWrtRuntimeConfigRenderer)->render([
            'addresses' => [
                ['interface' => 'br-lan', 'cidr' => '10.1.0.1/24'],
                ['interface' => 'br_lan', 'cidr' => '10.2.0.1/24'],
            ],
        ]);
    }

    public function test_it_rejects_uci_quote_injection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OpenWrtRuntimeConfigRenderer)->render(['management' => ['interface' => "eth0'\nconfig interface evil"]]);
    }

    public function test_it_renders_openwrt_network_and_dhcp_preview_from_router_configuration(): void
    {
        $renderer = new OpenWrtRuntimeConfigRenderer;

        $rendered = $renderer->render(
            [
                'management' => [
                    'interface' => 'mgmt0',
                    'cidr' => '192.0.2.1/24',
                ],
                'routes' => [
                    [
                        'destination' => 'default',
                        'gateway' => '192.0.2.254',
                        'metric' => 10,
                    ],
                ],
                'services' => [
                    'dns' => [
                        'forwarders' => ['1.1.1.1', '8.8.8.8'],
                        'search_domains' => ['corp.example'],
                    ],
                    'dhcp_servers' => [
                        [
                            'interface' => 'br0',
                            'start' => 100,
                            'limit' => 50,
                            'lease_time' => '12h',
                            'domain' => 'corp.example',
                            'dns_servers' => ['10.10.10.1'],
                        ],
                    ],
                    'pppoe_clients' => [
                        [
                            'name' => 'wan_pppoe',
                            'interface' => 'eth0',
                            'username' => 'uplink-user',
                            'service_name' => 'isp-service',
                            'default_route' => true,
                            'mtu' => 1492,
                            'download_kbps' => 50000,
                            'upload_kbps' => 10000,
                        ],
                    ],
                ],
                'interfaces' => [
                    ['name' => 'br0', 'type' => 'bridge', 'state' => 'up', 'mtu' => 1500],
                    ['name' => 'eth1', 'type' => 'ether', 'master' => 'br0', 'state' => 'up'],
                    ['name' => 'vlan10', 'type' => 'vlan', 'parent' => 'br0', 'vlan_id' => 10, 'state' => 'up'],
                ],
                'bridge_vlans' => [
                    ['device' => 'br0', 'vid' => 10, 'untagged' => false, 'pvid' => false, 'self' => true],
                ],
                'addresses' => [
                    [
                        'interface' => 'eth1',
                        'cidr' => '10.10.10.1/24',
                    ],
                    [
                        'interface' => 'br0',
                        'cidr' => '10.20.30.1/24',
                    ],
                ],
                'firewall' => [
                    'rules' => [
                        [
                            'chain' => 'forward',
                            'action' => 'drop',
                            'in_interface' => 'br0',
                            'out_interface' => 'mgmt0',
                        ],
                        [
                            'chain' => 'forward',
                            'action' => 'accept',
                            'in_interface' => 'br0',
                            'out_interface' => 'wan_pppoe',
                        ],
                    ],
                    'nat' => [
                        [
                            'chain' => 'postrouting',
                            'action' => 'masquerade',
                            'out_interface' => 'wan_pppoe',
                        ],
                        [
                            'chain' => 'postrouting',
                            'action' => 'snat',
                            'out_interface' => 'wan_pppoe',
                            'to_address' => '203.0.113.50',
                            'source' => '10.20.30.0/24',
                        ],
                        [
                            'chain' => 'prerouting',
                            'action' => 'dnat',
                            'in_interface' => 'wan_pppoe',
                            'to_address' => '10.20.30.10',
                            'destination' => '203.0.113.50',
                            'protocol' => 'tcp',
                        ],
                    ],
                ],
            ],
            [
                'services' => [
                    'pppoe_clients' => [
                        'wan_pppoe' => [
                            'password' => 'SecretPassword123!',
                        ],
                    ],
                ],
            ],
        );

        $this->assertStringContainsString("config interface 'management'", $rendered['network']);
        $this->assertStringContainsString("option ipaddr '192.0.2.1'", $rendered['network']);
        $this->assertStringContainsString("config device 'nur_br_br0'", $rendered['network']);
        $this->assertStringContainsString("option type 'bridge'", $rendered['network']);
        $this->assertStringContainsString("list ports 'eth1'", $rendered['network']);
        $this->assertStringContainsString("option vlan_filtering '1'", $rendered['network']);
        $this->assertStringContainsString("config device 'nur_vlan_vlan10'", $rendered['network']);
        $this->assertStringContainsString("option type '8021q'", $rendered['network']);
        $this->assertStringContainsString("option vid '10'", $rendered['network']);
        $this->assertStringContainsString("config bridge-vlan 'nur_bv_0'", $rendered['network']);
        $this->assertStringContainsString("option vlan '10'", $rendered['network']);
        $this->assertStringContainsString("list ports 'eth1:t'", $rendered['network']);
        $this->assertStringContainsString("config interface 'eth1'", $rendered['network']);
        $this->assertStringContainsString("config interface 'br0'", $rendered['network']);
        $this->assertStringContainsString("config interface 'wan_pppoe'", $rendered['network']);

        $hyphenated = $renderer->render([
            'management' => ['interface' => 'eth0', 'cidr' => '192.0.2.2/24'],
            'interfaces' => [
                ['name' => 'br-lan', 'type' => 'bridge', 'state' => 'up', 'mtu' => 1500],
                ['name' => 'eth1', 'type' => 'ether', 'master' => 'br-lan', 'state' => 'up'],
            ],
            'addresses' => [
                ['interface' => 'br-lan', 'cidr' => '10.10.10.2/24'],
            ],
            'services' => [
                'dns' => ['forwarders' => [], 'search_domains' => []],
                'dhcp_servers' => [
                    ['interface' => 'br-lan', 'start' => 100, 'limit' => 50, 'lease_time' => '1h'],
                ],
                'pppoe_clients' => [],
            ],
            'firewall' => ['rules' => [], 'nat' => []],
        ]);
        $this->assertStringContainsString("config device 'nur_br_br_lan'", $hyphenated['network']);
        $this->assertStringContainsString("option name 'br-lan'", $hyphenated['network']);
        $this->assertStringContainsString("config interface 'br_lan'", $hyphenated['network']);
        $this->assertStringContainsString("option device 'br-lan'", $hyphenated['network']);
        $this->assertStringContainsString("config dhcp 'br_lan'", $hyphenated['dhcp']);
        $this->assertStringContainsString("list network 'br_lan'", $hyphenated['firewall']);
        $this->assertStringContainsString("option username 'uplink-user'", $rendered['network']);
        $this->assertStringContainsString("option password '***redacted***'", $rendered['network']);
        $this->assertStringContainsString("option gateway '192.0.2.254'", $rendered['network']);

        $this->assertStringContainsString("config dnsmasq 'main'", $rendered['dhcp']);
        $this->assertStringContainsString("list server '1.1.1.1'", $rendered['dhcp']);
        $this->assertStringContainsString("config dhcp 'br0'", $rendered['dhcp']);
        $this->assertStringContainsString("list dhcp_option '6,10.10.10.1'", $rendered['dhcp']);
        $this->assertStringContainsString('# search domain corp.example', $rendered['dhcp']);

        $this->assertStringContainsString('config zone', $rendered['firewall']);
        $this->assertStringContainsString("option name 'management'", $rendered['firewall']);
        $this->assertStringContainsString("list network 'management'", $rendered['firewall']);
        $this->assertStringContainsString("option name 'wan'", $rendered['firewall']);
        $this->assertStringContainsString("option masq '1'", $rendered['firewall']);
        $this->assertStringContainsString("option src 'lan'", $rendered['firewall']);
        $this->assertStringContainsString("option dest 'management'", $rendered['firewall']);
        $this->assertStringContainsString("option dest 'wan'", $rendered['firewall']);
        $this->assertStringContainsString("config nat 'nur_snat_1'", $rendered['firewall']);
        $this->assertStringContainsString("option target 'SNAT'", $rendered['firewall']);
        $this->assertStringContainsString("option snat_ip '203.0.113.50'", $rendered['firewall']);
        $this->assertStringContainsString("config redirect 'nur_dnat_2'", $rendered['firewall']);
        $this->assertStringContainsString("option target 'DNAT'", $rendered['firewall']);
        $this->assertStringContainsString("option dest_ip '10.20.30.10'", $rendered['firewall']);
        $this->assertStringContainsString('"download_kbps": 50000', $rendered['pppoe_shaping']);
        $this->assertStringContainsString('"upload_kbps": 10000', $rendered['pppoe_shaping']);
        $this->assertStringContainsString('# nur_download_kbps 50000', $rendered['network']);
    }

    public function test_bras_devices_and_radius_dae_are_separate_from_wan_clients(): void
    {
        $rendered = (new OpenWrtRuntimeConfigRenderer)->render([
            'services' => ['pppoe_server' => ['enabled' => true, 'auth_mode' => 'radius',
                'radius' => ['address' => '198.18.6.3', 'dae_address' => '198.18.6.2']]],
        ]);
        $this->assertStringContainsString("list device 'nurbs+'", $rendered['firewall']);
        $this->assertStringNotContainsString("list device 'ppp+'", $rendered['firewall']);
        $this->assertStringContainsString("option src_ip '198.18.6.3'", $rendered['firewall']);
        $this->assertStringContainsString("option dest_port '3799'", $rendered['firewall']);
        $this->assertStringContainsString("option forward 'DROP'", $rendered['firewall']);
    }
}
