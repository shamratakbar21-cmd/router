<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ObservedState;
use App\Models\RouterNode;
use App\Models\User;
use App\Services\RouterAgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RouterManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_non_admin_user_cannot_access_router_management(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('routers.index'));

        $response->assertForbidden();
    }

    public function test_bootstrap_admin_is_redirected_to_password_rotation(): void
    {
        $user = User::factory()
            ->admin()
            ->requiresPasswordRotation()
            ->create([
                'password' => Hash::make('CurrentPassword123!'),
            ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('password-rotation.edit'));
    }

    public function test_bootstrap_admin_can_rotate_password(): void
    {
        $user = User::factory()
            ->admin()
            ->requiresPasswordRotation()
            ->create([
                'password' => Hash::make('CurrentPassword123!'),
            ]);

        $response = $this->actingAs($user)->put(route('password-rotation.update'), [
            'current_password' => 'CurrentPassword123!',
            'password' => 'ReplacementPassword123!',
            'password_confirmation' => 'ReplacementPassword123!',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'must_rotate_password' => false,
        ]);
    }

    public function test_admin_can_create_router_namespace_and_stage_revision(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->post(route('routers.store'), [
            'name' => 'Lab Router',
            'namespace_name' => 'nur-lab-1',
            'management_interface' => 'mgmt0',
            'management_cidr' => '192.0.2.1/24',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('router_nodes', [
            'namespace_name' => 'nur-lab-1',
            'status' => 'staged',
        ]);
        $this->assertDatabaseHas('configuration_revisions', [
            'revision_number' => 1,
        ]);
    }

    public function test_admin_can_view_desired_state_editor(): void
    {
        $user = User::factory()->admin()->create();
        $routerNode = RouterNode::factory()->create([
            'name' => 'Edge Router',
            'namespace_name' => 'nur-web-editor',
        ]);

        $response = $this->actingAs($user)->get(route('routers.desired-state.edit', $routerNode));

        $response->assertOk()
            ->assertSee('Configure Edge Router')
            ->assertSee('nur-web-editor')
            ->assertSee('Stage desired routing revision');
    }

    public function test_edit_router_form_does_not_render_stored_appliance_secrets(): void
    {
        $user = User::factory()->admin()->create();
        $routerNode = RouterNode::factory()->create([
            'appliance_host' => '192.0.2.2',
            'appliance_ssh_port' => 22,
            'appliance_ssh_user' => 'root',
            'appliance_ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nTEST-PRIVATE-KEY\n-----END OPENSSH PRIVATE KEY-----",
            'appliance_ssh_known_host' => '192.0.2.2 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITestKey',
        ]);

        $response = $this->actingAs($user)->get(route('routers.edit', $routerNode));

        $response->assertOk()
            ->assertDontSee('TEST-PRIVATE-KEY')
            ->assertDontSee('AAAAC3NzaC1lZDI1NTE5AAAAITestKey')
            ->assertSee('A private key is already stored for this router.')
            ->assertSee('A known host pin is already stored for this router.');
    }

    public function test_admin_can_update_router_without_reentering_stored_appliance_secrets(): void
    {
        $user = User::factory()->admin()->create();
        $routerNode = RouterNode::factory()->create([
            'name' => 'Lab Router',
            'namespace_name' => 'nur-live-appliance',
            'management_interface' => 'eth0',
            'management_cidr' => '192.0.2.2/24',
            'appliance_host' => '192.0.2.2',
            'appliance_ssh_port' => 22,
            'appliance_ssh_user' => 'root',
            'appliance_ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nKEEP-THIS-KEY\n-----END OPENSSH PRIVATE KEY-----",
            'appliance_ssh_known_host' => '192.0.2.2 ssh-ed25519 AAAAC3NzaKEEPTHISKNOWNHOST',
        ]);

        $response = $this->actingAs($user)->patch(route('routers.update', $routerNode), [
            'name' => 'Lab Router Updated',
            'namespace_name' => 'nur-live-appliance',
            'management_interface' => 'eth0',
            'management_cidr' => '192.0.2.3/24',
            'appliance_host' => '192.0.2.3',
            'appliance_ssh_port' => 2222,
            'appliance_ssh_user' => 'root',
            'appliance_ssh_private_key' => '',
            'appliance_ssh_known_host' => '',
        ]);

        $response->assertRedirect(route('routers.show', $routerNode));
        $routerNode->refresh();

        $this->assertSame('Lab Router Updated', $routerNode->name);
        $this->assertSame('192.0.2.3', $routerNode->appliance_host);
        $this->assertSame(2222, $routerNode->appliance_ssh_port);
        $this->assertSame("-----BEGIN OPENSSH PRIVATE KEY-----\nKEEP-THIS-KEY\n-----END OPENSSH PRIVATE KEY-----", $routerNode->appliance_ssh_private_key);
        $this->assertSame('192.0.2.2 ssh-ed25519 AAAAC3NzaKEEPTHISKNOWNHOST', $routerNode->appliance_ssh_known_host);
    }

    public function test_admin_can_stage_desired_state_revision_from_browser_form(): void
    {
        $user = User::factory()->admin()->create();
        $routerNode = RouterNode::factory()->create([
            'namespace_name' => 'nur-web-stage',
        ]);

        $response = $this->actingAs($user)
            ->from(route('routers.desired-state.edit', $routerNode))
            ->put(route('routers.desired-state.update', $routerNode), [
                'interfaces' => [
                    ['name' => 'br0', 'type' => 'bridge', 'state' => 'up', 'mtu' => 1500],
                    ['name' => '', 'type' => '', 'state' => ''],
                ],
                'addresses' => [
                    ['interface' => 'br0', 'cidr' => '198.51.100.1/24'],
                    ['interface' => '', 'cidr' => ''],
                ],
                'routes' => [
                    ['destination' => 'default', 'gateway' => '198.51.100.254', 'device' => 'br0', 'metric' => 10],
                    ['destination' => '', 'gateway' => '', 'device' => '', 'metric' => ''],
                ],
                'bridge_vlans' => [
                    ['device' => 'br0', 'vid' => 10, 'self' => '1', 'pvid' => '0', 'untagged' => '0'],
                    ['device' => '', 'vid' => '', 'self' => '0', 'pvid' => '0', 'untagged' => '0'],
                ],
                'forwarding' => [
                    'ipv4' => '1',
                ],
                'services' => [
                    'dns' => [
                        'forwarders' => ['1.1.1.1', ''],
                        'search_domains' => ['corp.example', ''],
                    ],
                    'dhcp_servers' => [
                        [
                            'interface' => 'br0',
                            'start' => 100,
                            'limit' => 50,
                            'lease_time' => '12h',
                            'domain' => 'corp.example',
                            'dns_servers' => ['10.10.10.1', ''],
                        ],
                        [
                            'interface' => '',
                            'start' => '',
                            'limit' => '',
                            'lease_time' => '',
                            'domain' => '',
                            'dns_servers' => ['', ''],
                        ],
                    ],
                    'pppoe_clients' => [
                        [
                            'name' => 'wan_pppoe',
                            'interface' => 'mgmt0',
                            'username' => 'uplink-user',
                            'password' => 'SecretPassword123!',
                            'service_name' => 'isp-service',
                            'default_route' => '1',
                            'mtu' => 1492,
                        ],
                        [
                            'name' => '',
                            'interface' => '',
                            'username' => '',
                            'password' => '',
                            'service_name' => '',
                            'default_route' => '0',
                            'mtu' => '',
                        ],
                    ],
                ],
                'firewall' => [
                    'rules' => [
                        ['chain' => 'forward', 'action' => 'accept', 'in_interface' => 'br0', 'out_interface' => 'mgmt0'],
                        ['chain' => '', 'action' => '', 'in_interface' => '', 'out_interface' => ''],
                    ],
                    'nat' => [
                        ['chain' => 'postrouting', 'action' => 'masquerade', 'out_interface' => 'mgmt0'],
                        ['chain' => '', 'action' => '', 'out_interface' => ''],
                    ],
                ],
            ]);

        $response->assertRedirect(route('routers.desired-state.edit', $routerNode));
        $response->assertSessionHas('status', 'Desired routing state staged as revision 1.');

        $routerNode->refresh();

        $this->assertSame('staged', $routerNode->status);
        $this->assertSame('bridge', data_get($routerNode->desired_configuration, 'interfaces.0.type'));
        $this->assertSame(1, count($routerNode->desired_configuration['interfaces'] ?? []));
        $this->assertSame('198.51.100.1/24', data_get($routerNode->desired_configuration, 'addresses.0.cidr'));
        $this->assertSame('1.1.1.1', data_get($routerNode->desired_configuration, 'services.dns.forwarders.0'));
        $this->assertSame('wan_pppoe', data_get($routerNode->desired_configuration, 'services.pppoe_clients.0.name'));
        $this->assertSame('SecretPassword123!', data_get($routerNode->desired_secret_configuration, 'services.pppoe_clients.wan_pppoe.password'));
        $this->assertSame('mgmt0', data_get($routerNode->desired_configuration, 'firewall.nat.0.out_interface'));
        $this->assertTrue((bool) data_get($routerNode->desired_configuration, 'forwarding.ipv4'));
        $this->assertDatabaseHas('configuration_revisions', [
            'router_node_id' => $routerNode->id,
            'revision_number' => 1,
        ]);
    }

    public function test_invalid_desired_state_browser_form_redirects_back_with_errors(): void
    {
        $user = User::factory()->admin()->create();
        $routerNode = RouterNode::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('routers.desired-state.edit', $routerNode))
            ->put(route('routers.desired-state.update', $routerNode), [
                'addresses' => [
                    ['interface' => 'br0', 'cidr' => '999.1.1.1/24'],
                ],
            ]);

        $response->assertRedirect(route('routers.desired-state.edit', $routerNode));
        $response->assertSessionHasErrors(['addresses.0.cidr']);
    }

    public function test_admin_can_apply_router_revision_and_record_observed_state(): void
    {
        $this->instance(RouterAgentClient::class, $this->fakeAgentClient());

        $user = User::factory()->admin()->create();
        $routerNode = RouterNode::factory()->create([
            'namespace_name' => 'nur-lab-2',
            'desired_configuration' => [],
        ]);
        $routerNode->revisions()->create([
            'user_id' => $user->id,
            'revision_number' => 1,
            'desired_configuration' => [
                'namespace' => 'nur-lab-2',
                'management' => ['interface' => 'mgmt0', 'cidr' => '192.0.2.1/24'],
            ],
        ]);
        $routerNode->update(['current_revision_id' => $routerNode->revisions()->first()->id]);

        $response = $this->actingAs($user)->post(route('routers.apply', $routerNode));

        $response->assertRedirect(route('routers.show', $routerNode));
        $this->assertSame(1, ObservedState::count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'router.revision.applied',
            'status' => 'success',
        ]);
    }

    public function test_admin_can_rollback_to_an_older_revision(): void
    {
        $this->instance(RouterAgentClient::class, $this->fakeAgentClient());

        $user = User::factory()->admin()->create();
        $routerNode = RouterNode::factory()->create([
            'namespace_name' => 'nur-lab-3',
            'management_cidr' => '192.0.2.10/24',
            'desired_configuration' => [],
        ]);
        $firstRevision = $routerNode->revisions()->create([
            'user_id' => $user->id,
            'revision_number' => 1,
            'desired_configuration' => [
                'namespace' => 'nur-lab-3',
                'management' => ['interface' => 'mgmt0', 'cidr' => '192.0.2.10/24'],
            ],
            'apply_status' => 'applied',
            'applied_at' => now(),
        ]);
        $secondRevision = $routerNode->revisions()->create([
            'user_id' => $user->id,
            'revision_number' => 2,
            'desired_configuration' => [
                'namespace' => 'nur-lab-3',
                'management' => ['interface' => 'mgmt0', 'cidr' => '192.0.2.20/24'],
            ],
        ]);
        $routerNode->update([
            'management_cidr' => '192.0.2.20/24',
            'current_revision_id' => $secondRevision->id,
        ]);

        $response = $this->actingAs($user)->post(route('routers.revisions.rollback', [$routerNode, $firstRevision]));

        $response->assertRedirect(route('routers.show', $routerNode));
        $routerNode->refresh();

        $this->assertSame('192.0.2.10/24', $routerNode->management_cidr);
        $this->assertDatabaseHas('configuration_revisions', [
            'rollback_of_revision_id' => $firstRevision->id,
            'apply_status' => 'applied',
        ]);
        $this->assertSame(1, AuditLog::query()->where('action', 'router.revision.applied')->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'router.revision.rollback_requested',
            'status' => 'success',
        ]);

    }

    public function test_admin_can_apply_revision_in_safe_mode_and_confirm_it(): void
    {
        $this->instance(RouterAgentClient::class, $this->fakeAgentClient());

        $user = User::factory()->admin()->create();
        $routerNode = RouterNode::factory()->create([
            'namespace_name' => 'nur-lab-safe-mode',
            'desired_configuration' => [],
        ]);
        $revision = $routerNode->revisions()->create([
            'user_id' => $user->id,
            'revision_number' => 1,
            'base_lock_version' => 0,
            'desired_configuration' => [
                'namespace' => 'nur-lab-safe-mode',
                'management' => ['interface' => 'mgmt0', 'cidr' => '192.0.2.1/24'],
            ],
        ]);
        $routerNode->update([
            'current_revision_id' => $revision->id,
            'desired_revision_id' => $revision->id,
        ]);

        $response = $this->actingAs($user)->post(route('routers.apply', $routerNode), [
            'expected_lock_version' => 0,
            'safe_mode_minutes' => 5,
        ]);

        $response->assertRedirect(route('routers.show', $routerNode));
        $routerNode->refresh();

        $this->assertSame('pending_confirm', $routerNode->safe_mode_status);
        $this->assertNotNull($routerNode->safe_mode_confirm_by);

        $confirmResponse = $this->actingAs($user)->post(route('routers.safe-mode.confirm', $routerNode));

        $confirmResponse->assertRedirect(route('routers.show', $routerNode));
        $routerNode->refresh();

        $this->assertSame('confirmed', $routerNode->safe_mode_status);
        $this->assertNull($routerNode->safe_mode_confirm_by);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'router.safe_mode.confirmed',
            'status' => 'success',
        ]);
    }

    public function test_reapplying_an_identical_revision_does_not_arm_safe_mode(): void
    {
        $this->instance(RouterAgentClient::class, $this->fakeAgentClient());

        $user = User::factory()->admin()->create();
        $configuration = [
            'namespace' => 'nur-lab-idempotent',
            'management' => ['interface' => 'mgmt0', 'cidr' => '192.0.2.1/24'],
        ];

        $routerNode = RouterNode::factory()->create([
            'namespace_name' => 'nur-lab-idempotent',
            'desired_configuration' => [],
            'applied_configuration' => $configuration,
            'applied_secret_configuration' => [],
            'configuration_lock_version' => 1,
            'status' => 'applied',
        ]);
        $revision = $routerNode->revisions()->create([
            'user_id' => $user->id,
            'revision_number' => 1,
            'base_lock_version' => 1,
            'desired_configuration' => $configuration,
            'desired_secret_configuration' => [],
        ]);
        $routerNode->update([
            'current_revision_id' => $revision->id,
            'desired_revision_id' => $revision->id,
        ]);

        $response = $this->actingAs($user)->post(route('routers.apply', $routerNode), [
            'expected_lock_version' => 1,
            'safe_mode_minutes' => 5,
        ]);

        $response->assertRedirect(route('routers.show', $routerNode));
        $routerNode->refresh();

        $this->assertSame('confirmed', $routerNode->safe_mode_status);
        $this->assertNull($routerNode->safe_mode_confirm_by);
        $this->assertSame('applied', $routerNode->status);
    }

    public function test_safe_mode_watchdog_rolls_back_to_previous_applied_revision_after_timeout(): void
    {
        $this->instance(RouterAgentClient::class, $this->fakeAgentClient());
        $this->travelTo(now());

        $user = User::factory()->admin()->create();
        $routerNode = RouterNode::factory()->create([
            'namespace_name' => 'nur-lab-watchdog',
            'management_cidr' => '192.0.2.10/24',
            'desired_configuration' => [],
            'applied_configuration' => [
                'namespace' => 'nur-lab-watchdog',
                'management' => ['interface' => 'mgmt0', 'cidr' => '192.0.2.10/24'],
            ],
            'configuration_lock_version' => 0,
        ]);
        $firstRevision = $routerNode->revisions()->create([
            'user_id' => $user->id,
            'revision_number' => 1,
            'base_lock_version' => 0,
            'desired_configuration' => [
                'namespace' => 'nur-lab-watchdog',
                'management' => ['interface' => 'mgmt0', 'cidr' => '192.0.2.10/24'],
            ],
            'apply_status' => 'applied',
            'applied_at' => now(),
            'applied_configuration' => [
                'namespace' => 'nur-lab-watchdog',
                'management' => ['interface' => 'mgmt0', 'cidr' => '192.0.2.10/24'],
            ],
        ]);
        $secondRevision = $routerNode->revisions()->create([
            'user_id' => $user->id,
            'revision_number' => 2,
            'base_lock_version' => 0,
            'desired_configuration' => [
                'namespace' => 'nur-lab-watchdog',
                'management' => ['interface' => 'mgmt0', 'cidr' => '192.0.2.20/24'],
            ],
        ]);
        $routerNode->update([
            'applied_revision_id' => $firstRevision->id,
            'current_revision_id' => $secondRevision->id,
            'desired_revision_id' => $secondRevision->id,
            'management_cidr' => '192.0.2.20/24',
        ]);

        $applyResponse = $this->actingAs($user)->post(route('routers.apply', $routerNode), [
            'expected_lock_version' => 0,
            'safe_mode_minutes' => 5,
        ]);

        $applyResponse->assertRedirect(route('routers.show', $routerNode));

        $this->travel(6)->minutes();
        $this->artisan('nur:safe-mode-watchdog')
            ->assertExitCode(0);

        $routerNode->refresh();

        $this->assertSame('192.0.2.10/24', data_get($routerNode->applied_configuration, 'management.cidr'));
        $this->assertSame('confirmed', $routerNode->safe_mode_status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'router.revision.rollback_requested',
            'status' => 'success',
        ]);
    }

    private function fakeAgentClient(): RouterAgentClient
    {
        return new class('/tmp/unused') extends RouterAgentClient
        {
            public function applyRoutingState(string $namespaceName, array $desiredConfiguration): array
            {
                return [
                    'namespace' => $namespaceName,
                    'links' => [
                        ['ifname' => data_get($desiredConfiguration, 'management.interface', 'mgmt0')],
                        ['ifname' => data_get($desiredConfiguration, 'interfaces.0.name', 'br0')],
                    ],
                    'addresses' => [[
                        'ifname' => data_get($desiredConfiguration, 'management.interface', 'mgmt0'),
                        'addr_info' => [[
                            'local' => explode('/', (string) data_get($desiredConfiguration, 'management.cidr', '192.0.2.1/24'))[0],
                            'prefixlen' => (int) explode('/', (string) data_get($desiredConfiguration, 'management.cidr', '192.0.2.1/24'))[1],
                        ]],
                    ]],
                    'routes' => data_get($desiredConfiguration, 'routes', []),
                    'bridge_vlans' => data_get($desiredConfiguration, 'bridge_vlans', []),
                    'firewall' => data_get($desiredConfiguration, 'firewall', []),
                    'forwarding' => data_get($desiredConfiguration, 'forwarding', ['ipv4' => '0']),
                ];
            }

            public function observeRoutingState(string $namespaceName): array
            {
                return [
                    'namespace' => $namespaceName,
                    'links' => [['ifname' => 'mgmt0']],
                    'addresses' => [['addr_info' => [['local' => '192.0.2.1']]]],
                    'routes' => [],
                ];
            }

            public function applyNamespace(string $namespaceName, string $interfaceName, string $managementCidr): array
            {
                return [
                    'namespace' => $namespaceName,
                    'links' => [['ifname' => $interfaceName]],
                    'addresses' => [['addr_info' => [['local' => explode('/', $managementCidr)[0]]]]],
                    'routes' => [],
                ];
            }

            public function observeNamespace(string $namespaceName): array
            {
                return [
                    'namespace' => $namespaceName,
                    'links' => [['ifname' => 'mgmt0']],
                    'addresses' => [['addr_info' => [['local' => '192.0.2.1']]]],
                    'routes' => [],
                ];
            }

            public function destroyNamespace(string $namespaceName): array
            {
                return [
                    'namespace' => $namespaceName,
                    'deleted' => true,
                ];
            }
        };
    }
}
