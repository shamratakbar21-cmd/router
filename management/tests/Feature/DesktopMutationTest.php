<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ConfigurationRevision;
use App\Models\ObservedState;
use App\Models\RouterNode;
use App\Models\User;
use App\Services\NeighborCapabilities;
use App\Services\OpenWrtRuntimeConfigRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DesktopMutationTest extends TestCase
{
    use RefreshDatabase;

    private function router(): RouterNode
    {
        $router = RouterNode::factory()->create([
            'management_interface' => 'eth0',
            'management_cidr' => '192.0.2.2/24',
            'desired_configuration' => [
                'addresses' => [['interface' => 'eth1', 'cidr' => '192.168.77.1/24']],
                'services' => ['dns' => ['forwarders' => ['1.1.1.1'], 'search_domains' => []]],
            ],
        ]);
        $revision = ConfigurationRevision::factory()->create(['router_node_id' => $router->id, 'desired_configuration' => $router->desired_configuration,
            'applied_configuration' => $router->desired_configuration, 'apply_status' => 'applied', 'applied_at' => now(), 'confirmed_at' => now(),
            'diff_summary' => ['neighbor_proof' => ['version' => 'fixture-v1', 'boot_id' => 'before-reboot',
                'pin_sha256' => hash('sha256', ''), 'operations' => NeighborCapabilities::OPERATIONS]]]);
        $router->update(['desired_revision_id' => $revision->id, 'applied_revision_id' => $revision->id, 'applied_configuration' => $router->desired_configuration]);
        ObservedState::factory()->create(['router_node_id' => $router->id, 'state' => ['health' => ['product_version' => 'fixture-v1', 'boot_id' => 'after-reboot'],
            'neighbors' => [], 'managed_neighbors' => [], 'neighbor_api' => ApplianceCapabilityTest::neighborApi(), 'neighbor_verification' => ['ok' => true]]]);

        return $router;
    }

    public function test_arp_add_disable_comment_remove_are_staged_and_preserve_other_state(): void
    {
        $router = $this->router();
        $this->actingAs(User::factory()->admin()->create());
        $appliedId = $router->applied_revision_id;
        $entry = ['ip' => '192.168.77.250', 'mac' => '02:00:00:00:77:fa', 'interface' => 'eth1', 'comment' => "Operator's test"];
        foreach (['add', 'disable', 'enable', 'comment', 'remove'] as $action) {
            $router->refresh();
            $this->postJson(route('routers.desktop.mutate', $router), [
                'module' => 'arp', 'action' => $action, 'index' => 0, 'entry' => $entry,
                'expected_revision_id' => $router->desired_revision_id,
                'expected_lock_version' => $router->configuration_lock_version,
            ])->assertOk()->assertJsonPath('state.router.status', 'staged');
            $router->refresh();
            $this->assertSame(['1.1.1.1'], $router->desired_configuration['services']['dns']['forwarders']);
            $this->assertSame($appliedId, $router->applied_revision_id);
            if ($action === 'disable') {
                $this->assertTrue($router->desired_configuration['static_neighbors'][0]['disabled']);
            }
        }
        $this->assertSame([], $router->desired_configuration['static_neighbors']);
        $this->assertSame(5, AuditLog::where('action', 'router.arp.staged')->count());
    }

    public function test_invalid_ip_mac_and_stale_revision_are_rejected(): void
    {
        $router = $this->router();
        $this->actingAs(User::factory()->admin()->create());
        $payload = ['module' => 'arp', 'action' => 'add', 'expected_revision_id' => $router->desired_revision_id, 'expected_lock_version' => 0,
            'entry' => ['ip' => '999.1.1.1', 'mac' => 'bad;command', 'interface' => 'eth1']];
        $this->postJson(route('routers.desktop.mutate', $router), $payload)->assertUnprocessable();
        $payload['expected_lock_version'] = 99;
        $this->postJson(route('routers.desktop.mutate', $router), $payload)->assertConflict();
        $this->assertSame(1, $router->revisions()->count());
    }

    public function test_non_admin_cannot_mutate(): void
    {
        $router = $this->router();
        $this->actingAs(User::factory()->create())->postJson(route('routers.desktop.mutate', $router), [])->assertForbidden();
    }

    public function test_static_neighbor_preview_omits_disabled_rows_and_does_not_emit_comments(): void
    {
        $config = ['management' => ['interface' => 'eth0', 'cidr' => '192.0.2.2/24'], 'static_neighbors' => [
            ['ip' => '192.168.77.250', 'mac' => '02:00:00:00:77:fa', 'interface' => 'eth1', 'comment' => "Operator's entry"],
            ['ip' => '192.168.77.251', 'mac' => '02:00:00:00:77:fb', 'interface' => 'eth1', 'disabled' => true],
        ]];
        $network = (new OpenWrtRuntimeConfigRenderer)->render($config)['network'];
        $this->assertStringContainsString("config neighbor 'nur_arp_0'", $network);
        $this->assertStringContainsString("option mac '02:00:00:00:77:fa'", $network);
        $this->assertStringNotContainsString('192.168.77.251', $network);
        $this->assertStringNotContainsString('Operator', $network);
    }
}
