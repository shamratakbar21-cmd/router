<?php

namespace Tests\Feature;

use App\Models\RouterNode;
use App\Models\User;
use App\Services\OpenWrtRuntimeConfigRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FirewallDesktopTest extends TestCase
{
    use RefreshDatabase;

    private function configuration(): array
    {
        return [
            'management' => ['interface' => 'eth0', 'cidr' => '192.0.2.2/24'],
            'addresses' => [['interface' => 'eth1', 'cidr' => '192.168.77.1/24'], ['interface' => 'eth2', 'cidr' => '10.0.4.15/24']],
            'routes' => [['destination' => 'default', 'gateway' => '10.0.4.2', 'device' => 'eth2']],
            'services' => ['dhcp_servers' => [['interface' => 'eth1']]],
        ];
    }

    private function requestRule(string $module, array $entry): TestResponse
    {
        $router = RouterNode::factory()->create(['management_interface' => 'eth0', 'management_cidr' => '192.0.2.2/24', 'desired_configuration' => $this->configuration()]);

        return $this->actingAs(User::factory()->admin()->create())->postJson(route('routers.desktop.mutate', $router), [
            'module' => $module, 'action' => 'add', 'expected_revision_id' => null, 'expected_lock_version' => 0, 'entry' => $entry,
        ]);
    }

    public function test_filter_ports_are_validated_and_staged(): void
    {
        $this->requestRule('filter', ['chain' => 'forward', 'action' => 'drop', 'protocol' => 'tcp', 'destination_port' => '443', 'in_interface' => 'eth1', 'out_interface' => 'eth2'])
            ->assertOk()->assertJsonPath('state.desired.configuration.firewall.rules.0.destination_port', '443');
    }

    public function test_invalid_ports_protocols_and_chain_direction_are_rejected(): void
    {
        foreach ([['protocol' => 'icmp', 'destination_port' => '443'], ['protocol' => 'tcp', 'destination_port' => '65536'], ['protocol' => 'tcp', 'destination_port' => '90-80'], ['chain' => 'input']] as $override) {
            $this->requestRule('filter', [...['chain' => 'forward', 'action' => 'drop', 'protocol' => 'tcp', 'in_interface' => 'eth1', 'out_interface' => 'eth2'], ...$override])->assertUnprocessable();
        }
    }

    public function test_dnat_requires_a_target_and_preserves_port_translation(): void
    {
        $base = ['chain' => 'prerouting', 'action' => 'dnat', 'protocol' => 'tcp', 'in_interface' => 'eth0', 'out_interface' => 'eth1', 'destination_port' => '18081', 'to_port' => '18080'];
        $this->requestRule('nat', $base)->assertUnprocessable();
        $this->requestRule('nat', [...$base, 'to_address' => '192.168.77.124'])->assertOk()->assertJsonPath('state.desired.configuration.firewall.nat.0.to_port', '18080');
    }

    public function test_disabled_nat_does_not_enable_masquerading_or_remove_wan_zone(): void
    {
        $configuration = $this->configuration();
        $configuration['firewall']['nat'] = [['chain' => 'postrouting', 'action' => 'masquerade', 'out_interface' => 'eth2', 'disabled' => true]];
        $firewall = (new OpenWrtRuntimeConfigRenderer)->render($configuration)['firewall'];
        $this->assertStringContainsString("option name 'wan'", $firewall);
        $this->assertStringNotContainsString("option masq '1'", $firewall);
    }

    public function test_restricted_masquerade_keeps_its_source_and_port_matches(): void
    {
        $configuration = $this->configuration();
        $configuration['firewall']['nat'] = [['chain' => 'postrouting', 'action' => 'masquerade', 'out_interface' => 'eth2', 'source' => '192.168.77.124', 'destination_port' => '443', 'protocol' => 'tcp']];
        $firewall = (new OpenWrtRuntimeConfigRenderer)->render($configuration)['firewall'];
        $this->assertStringContainsString("option target 'MASQUERADE'", $firewall);
        $this->assertStringContainsString("option src_ip '192.168.77.124'", $firewall);
        $this->assertStringContainsString("option dest_port '443'", $firewall);
        $this->assertStringNotContainsString("option masq '1'", $firewall);
    }

    public function test_dnat_and_filter_port_options_are_rendered_and_disabled_rules_omitted(): void
    {
        $configuration = $this->configuration();
        $configuration['firewall'] = [
            'rules' => [['chain' => 'forward', 'action' => 'drop', 'in_interface' => 'eth1', 'out_interface' => 'eth2', 'protocol' => 'tcp', 'destination_port' => '4444'], ['chain' => 'forward', 'action' => 'drop', 'source' => '203.0.113.200', 'disabled' => true]],
            'nat' => [['chain' => 'prerouting', 'action' => 'dnat', 'in_interface' => 'eth0', 'out_interface' => 'eth1', 'protocol' => 'tcp', 'destination_port' => '18081', 'to_port' => '18080', 'to_address' => '192.168.77.124']],
        ];
        $firewall = (new OpenWrtRuntimeConfigRenderer)->render($configuration)['firewall'];
        $this->assertStringContainsString("option src_dport '18081'", $firewall);
        $this->assertStringContainsString("option dest_port '18080'", $firewall);
        $this->assertStringContainsString("option dest 'lan'", $firewall);
        $this->assertStringContainsString("option dest_port '4444'", $firewall);
        $this->assertStringNotContainsString('203.0.113.200', $firewall);
    }
}
