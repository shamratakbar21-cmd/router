<?php

namespace Tests\Feature;

use App\Exceptions\ApplianceTransportException;
use App\Models\ConfigurationRevision;
use App\Models\ObservedState;
use App\Models\RouterNode;
use App\Models\User;
use App\Services\NeighborCapabilities;
use App\Services\OpenWrtApplianceClient;
use App\Services\OpenWrtOverlayExporter;
use App\Services\RouterConfigurationManager;
use App\Services\RouterStateProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplianceCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public static function neighborApi(): array
    {
        return ['schema_version' => 1, 'observation' => true,
            'operations' => ['observe', 'add', 'edit', 'delete', 'enable', 'disable', 'comment'],
            'disable_semantics' => 'remove-static-pin-allow-dynamic', 'persistence' => 'uci-network',
            'verification' => 'kernel-and-metadata'];
    }

    public function test_arp_edit_is_rejected_without_negotiated_capabilities(): void
    {
        $router = RouterNode::factory()->create();
        $this->actingAs(User::factory()->admin()->create())->postJson(route('routers.desktop.mutate', $router), [
            'module' => 'arp', 'action' => 'add', 'expected_revision_id' => null, 'expected_lock_version' => 0,
            'entry' => ['ip' => '198.51.100.2', 'mac' => '52:54:00:72:11:01', 'interface' => 'eth1'],
        ])->assertConflict();
        $this->assertSame(0, $router->revisions()->count());
    }

    public function test_neighbor_verification_requires_kernel_state_metadata_and_removal_of_old_pins(): void
    {
        $client = app(OpenWrtApplianceClient::class);
        $method = new \ReflectionMethod($client, 'matchesRequestedState');
        $entry = ['ip' => '198.51.100.2', 'mac' => '52:54:00:72:11:01', 'interface' => 'eth1', 'disabled' => false, 'comment' => 'Peer'];
        $observed = ['services' => ['dnsmasq_running' => true], 'neighbor_api' => self::neighborApi(), 'neighbor_verification' => ['ok' => true],
            'managed_neighbors' => [$entry], 'neighbors' => [['dst' => $entry['ip'], 'dev' => 'eth1', 'lladdr' => $entry['mac'], 'state' => ['PERMANENT']]]];
        $this->assertTrue($method->invoke($client, ['static_neighbors' => [$entry]], $observed));
        $observed['managed_neighbors'][0]['comment'] = 'Unpersisted';
        $this->assertFalse($method->invoke($client, ['static_neighbors' => [$entry]], $observed));
        $entry['disabled'] = true;
        $observed['managed_neighbors'] = [$entry];
        $this->assertFalse($method->invoke($client, ['static_neighbors' => [$entry]], $observed));
        $observed['neighbors'][0]['state'] = ['STALE'];
        $this->assertTrue($method->invoke($client, ['static_neighbors' => [$entry]], $observed));
        $observed['neighbors'][0]['state'] = ['PERMANENT'];
        $observed['managed_neighbors'] = [];
        $this->assertFalse($method->invoke($client, [], $observed, null, [$entry]));
    }

    public function test_ui_unlocks_only_after_confirmed_operation_proof_and_reboot_for_the_same_pinned_image(): void
    {
        $router = RouterNode::factory()->create();
        $state = ['health' => ['product_version' => 'candidate-test', 'boot_id' => 'first-boot'], 'neighbors' => [], 'managed_neighbors' => [],
            'neighbor_api' => self::neighborApi(), 'neighbor_verification' => ['ok' => true]];
        $this->assertFalse(NeighborCapabilities::ready($router, $state));
        $revision = ConfigurationRevision::factory()->create(['router_node_id' => $router->id, 'apply_status' => 'applied', 'applied_at' => now(),
            'diff_summary' => ['neighbor_proof' => ['version' => 'candidate-test', 'boot_id' => 'first-boot',
                'pin_sha256' => hash('sha256', ''), 'operations' => NeighborCapabilities::OPERATIONS]]]);
        $state['health']['boot_id'] = 'second-boot';
        $this->assertFalse(NeighborCapabilities::ready($router->fresh(), $state));
        $revision->update(['confirmed_at' => now()]);
        $this->assertTrue(NeighborCapabilities::ready($router->fresh(), $state));
        $state['health']['product_version'] = 'other-image';
        $this->assertFalse(NeighborCapabilities::ready($router->fresh(), $state));
        $state['health']['product_version'] = 'candidate-test';
        $router->appliance_ssh_known_host = 'different-console-pin';
        $this->assertFalse(NeighborCapabilities::ready($router, $state));
    }

    public function test_recovery_does_not_finalize_until_restored_metadata_and_kernel_pins_match(): void
    {
        $router = RouterNode::factory()->create(['appliance_host' => '127.0.0.1', 'appliance_ssh_user' => 'nur-mgmt', 'appliance_ssh_private_key' => 'fixture', 'appliance_ssh_known_host' => 'fixture']);
        $old = ['ip' => '198.51.100.2', 'mac' => '52:54:00:72:13:01', 'interface' => 'eth1', 'disabled' => false, 'comment' => 'Baseline'];
        $previous = ConfigurationRevision::factory()->create(['router_node_id' => $router->id, 'applied_configuration' => ['static_neighbors' => [$old]]]);
        $current = ConfigurationRevision::factory()->create(['router_node_id' => $router->id, 'revision_number' => 2,
            'operation_uuid' => 'recovery-arp-operation', 'desired_configuration' => ['static_neighbors' => []],
            'diff_summary' => ['previous_applied_revision_id' => $previous->id]]);
        $router->update(['applied_revision_id' => $current->id, 'applied_configuration' => ['static_neighbors' => []], 'safe_mode_status' => 'pending_confirm']);
        $state = ['apply_operations' => ['recovery-arp-operation' => 'rolled_back'], 'neighbor_api' => self::neighborApi(),
            'neighbor_verification' => ['ok' => true], 'managed_neighbors' => [$old], 'neighbors' => []];
        $good = $state;
        $good['neighbors'] = [['dst' => $old['ip'], 'lladdr' => $old['mac'], 'dev' => 'eth1', 'state' => ['PERMANENT']]];
        $this->mock(OpenWrtApplianceClient::class)->shouldReceive('observeConfiguration')->twice()->andReturn($state, $good);
        $manager = app(RouterConfigurationManager::class);
        try {
            $manager->recoverConfirmedConfiguration($router->fresh());
            $this->fail('An unverified rollback was finalized.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('has not verified restored ARP', $exception->getMessage());
        }
        $this->assertSame($current->id, $router->fresh()->applied_revision_id);
        $manager->recoverConfirmedConfiguration($router->fresh());
        $this->assertSame($previous->id, $router->fresh()->applied_revision_id);
    }

    public function test_static_neighbor_apply_is_rejected_before_upload_when_runtime_cannot_observe_neighbors(): void
    {
        $exporter = $this->mock(OpenWrtOverlayExporter::class);
        $exporter->shouldNotReceive('export');
        $client = new class($exporter) extends OpenWrtApplianceClient
        {
            public function observeConfiguration(RouterNode $routerNode): array
            {
                return ['links' => [], 'apply_operations' => []];
            }
        };
        $router = RouterNode::factory()->make();
        try {
            $client->applyConfiguration($router, ['static_neighbors' => [['ip' => '198.51.100.2', 'mac' => '52:54:00:72:00:01', 'interface' => 'eth1']]], [], 'capability-fixture');
            $this->fail('An unverifiable apply was uploaded.');
        } catch (ApplianceTransportException $exception) {
            $this->assertSame(ApplianceTransportException::KIND_COMMAND_REJECTED, $exception->kind);
            $this->assertStringContainsString('no configuration was uploaded', $exception->getMessage());
        }
    }

    public function test_neighbor_capability_requires_actual_runtime_telemetry_and_is_exposed_to_workspace(): void
    {
        $router = RouterNode::factory()->create();
        $this->actingAs(User::factory()->admin()->create())->getJson(route('routers.state.show', $router))
            ->assertOk()->assertJsonPath('runtime_capabilities.neighbor_management', false);
        ObservedState::factory()->create(['router_node_id' => $router->id, 'state' => ['neighbors' => []]]);
        $projected = app(RouterStateProjector::class)->project($router->fresh());
        $this->assertTrue($projected['runtime_capabilities']['neighbor_observation']);
        $this->assertFalse($projected['runtime_capabilities']['neighbor_management']);
        $payload = ['neighbors' => [], 'neighbor_api' => self::neighborApi(), 'neighbor_verification' => ['ok' => true]];
        $this->assertTrue(RouterStateProjector::supportsNeighborManagement($payload));
        $payload['neighbor_verification']['ok'] = false;
        $this->assertFalse(RouterStateProjector::supportsNeighborManagement($payload));
        $payload['neighbor_verification']['ok'] = true;
        array_pop($payload['neighbor_api']['operations']);
        $this->assertFalse(RouterStateProjector::supportsNeighborManagement($payload));
    }
}
