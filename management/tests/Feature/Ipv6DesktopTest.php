<?php

namespace Tests\Feature;

use App\Models\RouterNode;
use App\Models\User;
use App\Services\OpenWrtRuntimeConfigRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Ipv6DesktopTest extends TestCase
{
    use RefreshDatabase;

    public function test_ipv6_address_and_route_render_without_changing_ipv4(): void
    {
        $router = RouterNode::factory()->create(['management_interface' => 'eth0', 'management_cidr' => '192.0.2.2/24', 'desired_configuration' => ['addresses' => [['interface' => 'eth1', 'cidr' => '192.168.77.1/24']]]]);
        $this->actingAs(User::factory()->admin()->create());
        $send = function ($module, $entry) use ($router) {
            $node = $router->fresh();

            return $this->postJson(route('routers.desktop.mutate', $node), ['module' => $module, 'action' => 'add', 'entry' => $entry, 'expected_revision_id' => $node->desired_revision_id, 'expected_lock_version' => $node->configuration_lock_version]);
        };
        $send('ipv6_addresses', ['interface' => 'eth1', 'cidr' => 'fd77:77::1/64'])->assertOk()->assertJsonPath('state.desired.configuration.addresses.0.cidr', '192.168.77.1/24');
        $send('ipv6_routes', ['interface' => 'eth1', 'destination' => 'fd77:88::/64', 'gateway' => 'fd77:77::2', 'metric' => 10])->assertOk();
        $network = app(OpenWrtRuntimeConfigRenderer::class)->render($router->fresh()->desired_configuration)['network'];
        $this->assertStringContainsString("list ip6addr 'fd77:77::1/64'", $network);
        $this->assertStringContainsString("config route6 'nur6_route_0'", $network);
        $this->assertStringContainsString("option gateway 'fd77:77::2'", $network);
        $send('ipv6_addresses', ['interface' => 'eth1', 'cidr' => 'fd77:77::1/64'])->assertUnprocessable();
    }

    public function test_invalid_ipv6_addresses_and_management_edits_are_rejected(): void
    {
        $router = RouterNode::factory()->create(['management_interface' => 'eth0', 'desired_configuration' => ['addresses' => [['interface' => 'eth1', 'cidr' => '192.168.77.1/24']]]]);
        $this->actingAs(User::factory()->admin()->create());
        foreach ([['interface' => 'eth1', 'cidr' => '192.168.77.1/24'], ['interface' => 'eth1', 'cidr' => 'fd77::1/129'], ['interface' => 'eth0', 'cidr' => 'fd77::1/64']] as $entry) {
            $this->postJson(route('routers.desktop.mutate', $router), ['module' => 'ipv6_addresses', 'action' => 'add', 'entry' => $entry, 'expected_revision_id' => null, 'expected_lock_version' => 0])->assertUnprocessable();
        }
    }
}
