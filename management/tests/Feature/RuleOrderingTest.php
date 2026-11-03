<?php

namespace Tests\Feature;

use App\Models\RouterNode;
use App\Models\User;
use App\Services\OpenWrtRuntimeConfigRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuleOrderingTest extends TestCase
{
    use RefreshDatabase;

    private function node(): RouterNode
    {
        return RouterNode::factory()->create(['management_interface' => 'eth0', 'management_cidr' => '192.0.2.2/24', 'desired_configuration' => [
            'management' => ['interface' => 'eth0', 'cidr' => '192.0.2.2/24'],
            'addresses' => [['interface' => 'eth1', 'cidr' => '192.168.77.1/24'], ['interface' => 'eth2', 'cidr' => '10.0.4.15/24']],
            'routes' => [['destination' => 'default', 'gateway' => '10.0.4.2', 'device' => 'eth2']],
            'firewall' => ['rules' => [
                ['chain' => 'forward', 'action' => 'drop', 'protocol' => 'icmp', 'in_interface' => 'eth1', 'out_interface' => 'eth2', 'comment' => 'first'],
                ['chain' => 'forward', 'action' => 'accept', 'protocol' => 'icmp', 'in_interface' => 'eth1', 'out_interface' => 'eth2', 'comment' => 'second'],
            ], 'nat' => [
                ['chain' => 'postrouting', 'action' => 'masquerade', 'out_interface' => 'eth2', 'comment' => 'nat-first'],
                ['chain' => 'postrouting', 'action' => 'snat', 'out_interface' => 'eth2', 'to_address' => '10.0.4.15', 'disabled' => true, 'comment' => 'nat-second'],
            ]],
        ]]);
    }

    private function move(RouterNode $router, string $module, string $action, int $index, $revision = null, int $lock = 0)
    {
        return $this->postJson(route('routers.desktop.mutate', $router), ['module' => $module, 'action' => $action, 'index' => $index, 'expected_revision_id' => $revision, 'expected_lock_version' => $lock]);
    }

    public function test_filter_reordering_preserves_fields_and_changes_rendered_precedence(): void
    {
        $router = $this->node();
        $this->actingAs(User::factory()->admin()->create());
        $this->move($router, 'filter', 'move_up', 1)->assertOk()
            ->assertJsonPath('state.desired.configuration.firewall.rules.0.comment', 'second')
            ->assertJsonPath('state.desired.configuration.firewall.rules.1.comment', 'first')
            ->assertJsonPath('state.desired.configuration.firewall.nat.1.disabled', true);
        $rendered = app(OpenWrtRuntimeConfigRenderer::class)->render($router->fresh()->desired_configuration);
        $firewall = $rendered['firewall'];
        $this->assertLessThan(strpos($firewall, "option name 'nur-drop-"), strpos($firewall, "option name 'nur-accept-"));
        $this->assertSame('accept', $router->fresh()->desired_configuration['firewall']['rules'][0]['action']);
        $this->move($router, 'filter', 'move_down', 0)->assertConflict();
        $node = $router->fresh();
        $this->move($node, 'filter', 'move_down', 0, $node->desired_revision_id, $node->configuration_lock_version)->assertOk()
            ->assertJsonPath('state.desired.configuration.firewall.rules.0.comment', 'first');
    }

    public function test_nat_can_move_and_invalid_edges_or_modules_do_not_create_revisions(): void
    {
        $router = $this->node();
        $this->actingAs(User::factory()->admin()->create());
        $this->move($router, 'nat', 'move_up', 0)->assertUnprocessable();
        $this->move($router, 'filter', 'move_down', 1)->assertUnprocessable();
        $this->move($router, 'addresses', 'move_down', 0)->assertUnprocessable();
        $this->assertNull($router->fresh()->desired_revision_id);
        $this->move($router, 'nat', 'move_down', 0)->assertOk()
            ->assertJsonPath('state.desired.configuration.firewall.nat.0.comment', 'nat-second')
            ->assertJsonPath('state.desired.configuration.firewall.nat.0.disabled', true);
    }
}
