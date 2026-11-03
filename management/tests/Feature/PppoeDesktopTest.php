<?php

namespace Tests\Feature;

use App\Models\RouterNode;
use App\Models\User;
use App\Services\OpenWrtRuntimeConfigRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PppoeDesktopTest extends TestCase
{
    use RefreshDatabase;

    public function test_pppoe_secret_is_separate_preserved_on_edit_and_removed_with_client(): void
    {
        $router = RouterNode::factory()->create(['management_interface' => 'eth0', 'management_cidr' => '192.0.2.2/24', 'desired_configuration' => ['addresses' => [['interface' => 'eth1', 'cidr' => '192.168.77.1/24']]]]);
        $this->actingAs(User::factory()->admin()->create());
        $entry = ['name' => 'pppoe-test', 'interface' => 'eth1', 'username' => 'test-user', 'password' => 'unit-secret-sentinel', 'mtu' => 1492, 'download_kbps' => 0, 'upload_kbps' => 0, 'default_route' => false];
        $send = function ($action, $data) use ($router) {
            $node = $router->fresh();

            return $this->postJson(route('routers.desktop.mutate', $node), ['module' => 'pppoe', 'action' => $action, 'index' => 0, 'entry' => $data, 'expected_revision_id' => $node->desired_revision_id, 'expected_lock_version' => $node->configuration_lock_version]);
        };
        $send('add', $entry)->assertOk()->assertDontSee('unit-secret-sentinel');
        $node = $router->fresh();
        $this->assertArrayNotHasKey('password', $node->desired_configuration['services']['pppoe_clients'][0]);
        $this->assertSame('unit-secret-sentinel', $node->desired_secret_configuration['services']['pppoe_clients']['pppoe-test']['password']);
        $rendered = app(OpenWrtRuntimeConfigRenderer::class)->render($node->desired_configuration, $node->desired_secret_configuration, false);
        $this->assertStringContainsString("option password 'unit-secret-sentinel'", $rendered['network']);
        $send('update', [...$entry, 'password' => '', 'mtu' => 1480])->assertOk()->assertDontSee('unit-secret-sentinel');
        $this->assertSame('unit-secret-sentinel', $router->fresh()->desired_secret_configuration['services']['pppoe_clients']['pppoe-test']['password']);
        $send('remove', [])->assertOk();
        $this->assertArrayNotHasKey('pppoe-test', $router->fresh()->desired_secret_configuration['services']['pppoe_clients']);
    }
}
