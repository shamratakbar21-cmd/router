<?php

namespace Tests\Feature;

use App\Models\RouterNode;
use App\Models\User;
use App\Services\OpenWrtRuntimeConfigRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WireGuardDesktopTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_key_is_private_and_peer_renders_in_default_deny_zone(): void
    {
        $router = RouterNode::factory()->create(['management_interface' => 'eth0', 'management_cidr' => '192.0.2.2/24', 'desired_configuration' => ['addresses' => [['interface' => 'eth1', 'cidr' => '192.168.77.1/24']]]]);
        $this->actingAs(User::factory()->admin()->create());
        $send = function ($module, $action, $entry = [], $index = 0) use ($router) {
            $node = $router->fresh();

            return $this->postJson(route('routers.desktop.mutate', $node), ['module' => $module, 'action' => $action, 'entry' => $entry, 'index' => $index, 'expected_revision_id' => $node->desired_revision_id, 'expected_lock_version' => $node->configuration_lock_version]);
        };
        $interface = ['name' => 'wgtest', 'listen_port' => 51830, 'addresses' => ['198.19.0.1/24'], 'mtu' => 1420];
        $send('wg_interfaces', 'add', $interface)->assertOk();
        $key = $router->fresh()->desired_secret_configuration['wireguard']['wgtest']['private_key'];
        $this->assertSame(32, strlen(base64_decode($key)));
        $public = base64_encode(sodium_crypto_scalarmult_base(base64_decode($key)));
        $this->assertSame($public, $router->fresh()->desired_configuration['services']['wireguard']['interfaces'][0]['public_key']);
        $send('wg_interfaces', 'update', [...$interface, 'private_key' => ''])->assertOk()->assertDontSee($key);
        $this->assertSame($key, $router->fresh()->desired_secret_configuration['wireguard']['wgtest']['private_key']);
        $peer = ['interface' => 'wgtest', 'public_key' => base64_encode(sodium_crypto_scalarmult_base(random_bytes(32))), 'allowed_ips' => ['198.19.0.2/32'], 'endpoint_host' => '192.0.2.1', 'endpoint_port' => 51831, 'persistent_keepalive' => 25];
        $send('wg_peers', 'add', $peer)->assertOk()->assertDontSee($key);
        $send('wg_peers', 'add', $peer)->assertUnprocessable();
        $node = $router->fresh();
        $rendered = app(OpenWrtRuntimeConfigRenderer::class)->render($node->desired_configuration, $node->desired_secret_configuration);
        $this->assertStringNotContainsString($key, $rendered['network']);
        $this->assertStringContainsString("option proto 'wireguard'", $rendered['network']);
        $this->assertStringContainsString('config wireguard_wgtest', $rendered['network']);
        $this->assertMatchesRegularExpression("/option name 'vpn'.*?option input 'DROP'/s", $rendered['firewall']);
        $send('wg_interfaces', 'remove')->assertUnprocessable();
        $send('wg_peers', 'remove')->assertOk();
        $send('wg_interfaces', 'remove')->assertOk();
        $this->assertArrayNotHasKey('wgtest', $router->fresh()->desired_secret_configuration['wireguard']);
    }

    public function test_invalid_interface_names_keys_and_peer_routes_are_rejected(): void
    {
        $router = RouterNode::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        foreach ([['name' => 'wg.test'], ['listen_port' => 65536], ['private_key' => 'invalid'], ['addresses' => ['999.1.1.1/24']]] as $override) {
            $this->postJson(route('routers.desktop.mutate', $router), ['module' => 'wg_interfaces', 'action' => 'add', 'entry' => [...['name' => 'wgtest', 'listen_port' => 51830, 'addresses' => ['198.19.0.1/24']], ...$override], 'expected_revision_id' => null, 'expected_lock_version' => 0])->assertUnprocessable();
        }
    }
}
