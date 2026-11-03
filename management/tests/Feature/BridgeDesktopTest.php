<?php

namespace Tests\Feature;

use App\Models\RouterNode;
use App\Models\User;
use App\Services\OpenWrtRuntimeConfigRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BridgeDesktopTest extends TestCase
{
    use RefreshDatabase;

    private function configuration(): array
    {
        return ['management' => ['interface' => 'eth0', 'cidr' => '192.0.2.2/24'], 'interfaces' => [
            ['name' => 'br-test', 'type' => 'bridge'],
            ['name' => 'vlan100', 'type' => 'vlan', 'parent' => 'eth1', 'vlan_id' => 100, 'mtu' => 1400],
            ['name' => 'vlan200', 'type' => 'vlan', 'parent' => 'eth1', 'vlan_id' => 200, 'master' => 'br-test'],
        ], 'addresses' => [['interface' => 'eth1', 'cidr' => '192.168.77.1/24']]];
    }

    private function mutation(string $module, string $action, array $entry, int $index = 0, ?array $configuration = null)
    {
        $router = RouterNode::factory()->create(['management_interface' => 'eth0', 'management_cidr' => '192.0.2.2/24', 'desired_configuration' => $configuration ?? $this->configuration()]);

        return $this->actingAs(User::factory()->admin()->create())->postJson(route('routers.desktop.mutate', $router), ['module' => $module, 'action' => $action, 'entry' => $entry, 'index' => $index, 'expected_revision_id' => null, 'expected_lock_version' => 0]);
    }

    public function test_bridge_port_assignment_preserves_vlan_and_renders_membership(): void
    {
        $result = $this->mutation('ports', 'add', ['name' => 'vlan100', 'master' => 'br-test'])->assertOk()
            ->assertJsonPath('state.desired.configuration.interfaces.1.master', 'br-test')
            ->assertJsonPath('state.desired.configuration.interfaces.1.mtu', 1400);
        $network = app(OpenWrtRuntimeConfigRenderer::class)->render($result->json('state.desired.configuration'))['network'];
        $this->assertStringContainsString("list ports 'vlan100'", $network);
        $this->mutation('ports', 'remove', [], 2)->assertOk()->assertJsonMissingPath('state.desired.configuration.interfaces.2.master')
            ->assertJsonPath('state.desired.configuration.interfaces.2.vlan_id', 200);
    }

    public function test_routed_ports_bridge_nesting_and_invalid_memberships_are_rejected(): void
    {
        foreach (['eth0', 'eth1', 'br-test', 'missing'] as $port) {
            $this->mutation('ports', 'add', ['name' => $port, 'master' => 'br-test'])->assertUnprocessable();
        }
        $this->mutation('bridge_vlans', 'add', ['device' => 'br-test', 'vid' => 20, 'port' => 'vlan100'])->assertUnprocessable();
        $this->mutation('bridge_vlans', 'add', ['device' => 'br-test', 'vid' => 4095])->assertUnprocessable();
        $this->mutation('bridge_vlans', 'add', ['device' => 'br-test', 'vid' => 20, 'pvid' => true, 'untagged' => false])->assertUnprocessable();
    }

    public function test_vlan_membership_is_rendered_and_protects_port_removal(): void
    {
        $entry = ['device' => 'br-test', 'vid' => 20, 'port' => 'vlan200', 'pvid' => true, 'untagged' => true];
        $result = $this->mutation('bridge_vlans', 'add', $entry)->assertOk();
        $network = app(OpenWrtRuntimeConfigRenderer::class)->render($result->json('state.desired.configuration'))['network'];
        $this->assertStringContainsString("list ports 'vlan200:u*'", $network);
        $config = [...$this->configuration(), 'bridge_vlans' => [$entry]];
        $this->mutation('ports', 'remove', [], 2, $config)->assertUnprocessable();
        $this->mutation('bridge_vlans', 'add', $entry, 0, $config)->assertUnprocessable();
    }

    public function test_memberships_with_same_vid_share_one_native_section(): void
    {
        $config = [...$this->configuration(), 'bridge_vlans' => [
            ['device' => 'br-test', 'vid' => 20, 'port' => 'vlan100'],
            ['device' => 'br-test', 'vid' => 20, 'port' => 'vlan200', 'untagged' => true],
        ]];
        $network = app(OpenWrtRuntimeConfigRenderer::class)->render($config)['network'];
        $this->assertSame(1, substr_count($network, 'config bridge-vlan'));
        $this->assertStringContainsString("list ports 'vlan100:t'", $network);
        $this->assertStringContainsString("list ports 'vlan200:u'", $network);
    }
}
