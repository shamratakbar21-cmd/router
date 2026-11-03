<?php

namespace Tests\Unit;

use App\Services\OpenWrtRuntimeConfigRenderer;
use PHPUnit\Framework\TestCase;

class StaticWanFirewallTest extends TestCase
{
    public function test_static_nat_uplink_gets_a_wan_zone_and_lan_forwarding(): void
    {
        $rendered = (new OpenWrtRuntimeConfigRenderer)->render([
            'management' => ['interface' => 'eth0', 'cidr' => '192.0.2.2/24'],
            'addresses' => [
                ['interface' => 'eth1', 'cidr' => '192.168.77.1/24'],
                ['interface' => 'eth2', 'cidr' => '10.0.4.15/24'],
            ],
            'services' => ['dhcp_servers' => [['interface' => 'eth1']]],
            'firewall' => ['nat' => [['action' => 'masquerade', 'out_interface' => 'eth2']]],
        ]);
        $this->assertMatchesRegularExpression("/option name 'wan'\\s+list network 'eth2'/", $rendered['firewall']);
        $this->assertStringContainsString("option masq '1'", $rendered['firewall']);
        $this->assertStringContainsString("option src 'lan'\n    option dest 'wan'", $rendered['firewall']);
    }

    public function test_nat_does_not_reclassify_management_lan_or_unknown_devices_as_wan(): void
    {
        $rendered = (new OpenWrtRuntimeConfigRenderer)->render([
            'management' => ['interface' => 'eth0', 'cidr' => '192.0.2.2/24'],
            'addresses' => [
                ['interface' => 'eth0', 'cidr' => '192.0.2.2/24'],
                ['interface' => 'eth1', 'cidr' => '192.168.77.1/24'],
            ],
            'services' => ['dhcp_servers' => [['interface' => 'eth1']]],
            'firewall' => ['nat' => [
                ['action' => 'masquerade', 'out_interface' => 'eth0'],
                ['action' => 'masquerade', 'out_interface' => 'eth1'],
                ['action' => 'masquerade', 'out_interface' => 'missing0'],
            ]],
        ]);
        $this->assertStringNotContainsString("option name 'wan'", $rendered['firewall']);
    }
}
