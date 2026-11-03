<?php

namespace Tests\Feature;

use App\Models\RouterNode;
use App\Models\User;
use App\Services\OpenWrtRuntimeConfigRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceDesktopTest extends TestCase
{
    use RefreshDatabase;

    private function requestDevice(array $entry, string $action = 'add', array $extra = [])
    {
        $router = RouterNode::factory()->create(['management_interface' => 'eth0', 'management_cidr' => '192.0.2.2/24', 'desired_configuration' => [
            'management' => ['interface' => 'eth0', 'cidr' => '192.0.2.2/24'],
            'addresses' => [['interface' => 'eth1', 'cidr' => '192.168.77.1/24']],
            ...$extra,
        ]]);

        return $this->actingAs(User::factory()->admin()->create())->postJson(route('routers.desktop.mutate', $router), [
            'module' => 'devices', 'action' => $action, 'index' => 0, 'entry' => $entry, 'expected_revision_id' => null, 'expected_lock_version' => 0,
        ]);
    }

    public function test_bridge_and_vlan_render_native_devices(): void
    {
        $result = $this->requestDevice(['name' => 'br-test', 'type' => 'bridge', 'mtu' => 1500, 'vlan_filtering' => true])->assertOk();
        $network = app(OpenWrtRuntimeConfigRenderer::class)->render($result->json('state.desired.configuration'))['network'];
        $this->assertStringContainsString("option name 'br-test'", $network);
        $this->assertStringContainsString("option vlan_filtering '1'", $network);
        $result = $this->requestDevice(['name' => 'vlan100', 'type' => 'vlan', 'parent' => 'eth1', 'vlan_id' => 100, 'mtu' => 1400])->assertOk();
        $network = app(OpenWrtRuntimeConfigRenderer::class)->render($result->json('state.desired.configuration'))['network'];
        $this->assertStringContainsString("option type '8021q'", $network);
        $this->assertStringContainsString("option ifname 'eth1'", $network);
        $this->assertStringContainsString("option vid '100'", $network);
        $this->assertStringContainsString("option mtu '1400'", $network);
    }

    public function test_invalid_or_duplicate_devices_are_rejected(): void
    {
        foreach ([['name' => 'eth0', 'type' => 'bridge'], ['name' => 'bad name', 'type' => 'bridge'], ['name' => 'vlan100', 'type' => 'vlan', 'parent' => 'eth1', 'vlan_id' => 4095], ['name' => 'vlan100', 'type' => 'vlan', 'parent' => 'missing', 'vlan_id' => 100], ['name' => 'vlan100', 'type' => 'vlan']] as $entry) {
            $this->requestDevice($entry)->assertUnprocessable();
        }
        $this->requestDevice(['name' => 'br-test', 'type' => 'bridge'], 'add', ['interfaces' => [['name' => 'br-test', 'type' => 'bridge']]])->assertUnprocessable();
    }

    public function test_used_devices_and_physical_interfaces_cannot_be_removed(): void
    {
        $this->requestDevice([], 'remove', ['interfaces' => [['name' => 'eth1', 'type' => 'ether']]])->assertUnprocessable();
        $this->requestDevice([], 'remove', ['interfaces' => [['name' => 'br-test', 'type' => 'bridge']], 'addresses' => [['interface' => 'br-test', 'cidr' => '198.18.0.1/24']]])->assertUnprocessable();
        $this->requestDevice([], 'remove', ['interfaces' => [['name' => 'br-test', 'type' => 'bridge']]])->assertOk()->assertJsonPath('state.desired.configuration.interfaces', []);
    }
}
