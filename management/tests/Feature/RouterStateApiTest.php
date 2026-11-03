<?php

namespace Tests\Feature;

use App\Models\ObservedState;
use App\Models\RouterNode;
use App\Models\User;
use App\Services\RouterAgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouterStateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_router_state_api(): void
    {
        $routerNode = RouterNode::factory()->create();

        $response = $this->get(route('routers.state.show', $routerNode));

        $response->assertRedirect(route('login'));
    }

    public function test_non_admin_user_cannot_access_router_state_api(): void
    {
        $routerNode = RouterNode::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('routers.state.show', $routerNode));

        $response->assertForbidden();
    }

    public function test_admin_can_read_normalized_router_state(): void
    {
        $user = User::factory()->admin()->create();
        $routerNode = RouterNode::factory()->create([
            'namespace_name' => 'nur-api-1',
            'desired_configuration' => [
                'management' => [
                    'interface' => 'mgmt0',
                    'cidr' => '192.0.2.1/24',
                ],
                'routes' => [
                    ['destination' => '0.0.0.0/0', 'gateway' => '192.0.2.254'],
                ],
            ],
            'applied_configuration' => [
                'management' => [
                    'interface' => 'mgmt0',
                    'cidr' => '192.0.2.2/24',
                ],
            ],
            'safe_mode_status' => 'pending_confirm',
        ]);
        $routerNode->observedStates()->create([
            'source' => 'agent',
            'summary' => ['namespace' => 'nur-api-1'],
            'state' => [
                'links' => [
                    ['ifname' => 'mgmt0', 'operstate' => 'UP', 'mtu' => 1500],
                ],
                'addresses' => [
                    [
                        'ifname' => 'mgmt0',
                        'addr_info' => [
                            ['family' => 'inet', 'local' => '192.0.2.2', 'prefixlen' => 24, 'scope' => 'global'],
                        ],
                    ],
                ],
                'routes' => [
                    ['dst' => 'default', 'gateway' => '192.0.2.254', 'dev' => 'mgmt0', 'protocol' => 'static', 'flags' => ['onlink']],
                ],
                'bridge_vlans' => [],
                'firewall' => ['nftables' => []],
                'forwarding' => ['ipv4' => '1'],
            ],
            'recorded_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson(route('routers.state.show', $routerNode));

        $response->assertOk()
            ->assertJsonPath('router.namespace', 'nur-api-1')
            ->assertJsonPath('safe_mode.status', 'pending_confirm')
            ->assertJsonPath('desired.normalized.interfaces.0.name', 'mgmt0')
            ->assertJsonPath('desired.normalized.addresses.0.cidr', '192.0.2.1/24')
            ->assertJsonPath('observed.normalized.interfaces.0.name', 'mgmt0')
            ->assertJsonPath('observed.normalized.addresses.0.cidr', '192.0.2.2/24')
            ->assertJsonPath('observed.normalized.routes.0.destination', 'default')
            ->assertJsonPath('observed.normalized.routes.0.flags', ['onlink'])
            ->assertJsonPath('observed.normalized.forwarding.ipv4', '1')
            ->assertJsonPath('diff.desired_vs_applied.0.path', 'management.cidr');
    }

    public function test_admin_can_refresh_router_state_from_agent(): void
    {
        $this->instance(RouterAgentClient::class, new class('/tmp/unused') extends RouterAgentClient
        {
            public function observeRoutingState(string $namespaceName): array
            {
                return [
                    'namespace' => $namespaceName,
                    'links' => [
                        ['ifname' => 'mgmt0', 'operstate' => 'UP', 'mtu' => 1500],
                    ],
                    'addresses' => [
                        [
                            'ifname' => 'mgmt0',
                            'addr_info' => [
                                ['family' => 'inet', 'local' => '198.51.100.10', 'prefixlen' => 24, 'scope' => 'global'],
                            ],
                        ],
                    ],
                    'routes' => [
                        ['dst' => 'default', 'gateway' => '198.51.100.1', 'dev' => 'mgmt0', 'protocol' => 'static'],
                    ],
                    'bridge_vlans' => [],
                    'firewall' => [
                        'nftables' => [
                            ['rule' => ['family' => 'inet', 'table' => 'filter']],
                        ],
                    ],
                    'forwarding' => ['ipv4' => '1'],
                    'observed_at' => now()->toIso8601String(),
                    'observation_op' => 'routing.observe',
                ];
            }
        });

        $user = User::factory()->admin()->create();
        $routerNode = RouterNode::factory()->create([
            'namespace_name' => 'nur-api-2',
        ]);

        $response = $this->actingAs($user)->getJson(route('routers.state.show', [$routerNode, 'refresh' => 1]));

        $response->assertOk()
            ->assertJsonPath('observed.normalized.addresses.0.cidr', '198.51.100.10/24')
            ->assertJsonPath('observed.normalized.forwarding.ipv4', '1');

        $this->assertSame(1, ObservedState::count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'router.state.refreshed',
            'status' => 'success',
        ]);
    }
}
