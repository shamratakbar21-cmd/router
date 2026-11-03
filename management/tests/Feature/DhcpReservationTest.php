<?php

namespace Tests\Feature;

use App\Models\RouterNode;
use App\Models\User;
use App\Services\OpenWrtRuntimeConfigRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DhcpReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_renders_and_can_be_disabled_without_affecting_server(): void
    {
        $router = RouterNode::factory()->create(['management_interface' => 'eth0', 'management_cidr' => '192.0.2.2/24', 'desired_configuration' => ['addresses' => [['interface' => 'eth1', 'cidr' => '192.168.77.1/24']], 'services' => ['dhcp_servers' => [['interface' => 'eth1']]]]]);
        $this->actingAs(User::factory()->admin()->create());
        $send = function ($action, $entry = []) use ($router) {
            $node = $router->fresh();

            return $this->postJson(route('routers.desktop.mutate', $node), ['module' => 'reservations', 'action' => $action, 'entry' => $entry, 'index' => 0, 'expected_revision_id' => $node->desired_revision_id, 'expected_lock_version' => $node->configuration_lock_version]);
        };
        $entry = ['interface' => 'eth1', 'ip' => '192.168.77.250', 'mac' => '02:00:00:00:77:fa', 'hostname' => 'reserved-client', 'lease_time' => '12h'];
        $send('add', $entry)->assertOk();
        $rendered = app(OpenWrtRuntimeConfigRenderer::class)->render($router->fresh()->desired_configuration)['dhcp'];
        $this->assertStringContainsString("option ip '192.168.77.250'", $rendered);
        $this->assertStringContainsString("option name 'reserved-client'", $rendered);
        $send('add', [...$entry, 'ip' => '192.168.77.251'])->assertUnprocessable();
        $send('disable')->assertOk();
        $rendered = app(OpenWrtRuntimeConfigRenderer::class)->render($router->fresh()->desired_configuration)['dhcp'];
        $this->assertStringNotContainsString('config host', $rendered);
        $this->assertStringContainsString("config dhcp 'eth1'", $rendered);
        $send('enable')->assertOk();
        $send('remove')->assertOk()->assertJsonPath('state.desired.configuration.services.dhcp_reservations', []);
    }

    public function test_network_broadcast_router_and_wrong_subnet_are_rejected(): void
    {
        $router = RouterNode::factory()->create(['desired_configuration' => ['addresses' => [['interface' => 'eth1', 'cidr' => '192.168.77.1/24']], 'services' => ['dhcp_servers' => [['interface' => 'eth1']]]]]);
        $this->actingAs(User::factory()->admin()->create());
        foreach (['192.168.77.0', '192.168.77.255', '192.168.77.1', '192.168.78.5'] as $ip) {
            $this->postJson(route('routers.desktop.mutate', $router), ['module' => 'reservations', 'action' => 'add', 'entry' => ['interface' => 'eth1', 'ip' => $ip, 'mac' => '02:00:00:00:77:fa', 'lease_time' => '12h'], 'expected_revision_id' => null, 'expected_lock_version' => 0])->assertUnprocessable();
        }
    }
}
