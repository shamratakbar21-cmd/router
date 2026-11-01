<?php

namespace Tests\Feature;

use App\Models\RouterNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouterDesiredStateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_desired_state_api(): void
    {
        $routerNode = RouterNode::factory()->create();

        $response = $this->get(route('routers.desired-state.show', $routerNode));

        $response->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_update_desired_state(): void
    {
        $routerNode = RouterNode::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson(route('routers.desired-state.update', $routerNode), [
            'interfaces' => [['name' => 'br0', 'type' => 'bridge', 'state' => 'up']],
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_read_desired_state_projection(): void
    {
        $routerNode = RouterNode::factory()->create([
            'desired_configuration' => [
                'namespace' => 'nur-ds-1',
                'management' => [
                    'interface' => 'mgmt0',
                    'cidr' => '192.0.2.1/24',
                ],
                'interfaces' => [
                    ['name' => 'br0', 'type' => 'bridge', 'state' => 'up'],
                ],
                'routes' => [
                    ['destination' => 'default', 'gateway' => '192.0.2.254'],
                ],
            ],
        ]);
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->getJson(route('routers.desired-state.show', $routerNode));

        $response->assertOk()
            ->assertJsonPath('desired.revision_id', null)
            ->assertJsonPath('desired.normalized.interfaces.0.name', 'mgmt0')
            ->assertJsonPath('desired.normalized.interfaces.1.name', 'br0')
            ->assertJsonPath('desired.normalized.routes.0.destination', 'default');
    }

    public function test_admin_can_stage_desired_state_revision(): void
    {
        $routerNode = RouterNode::factory()->create();
        $user = User::factory()->admin()->create();

        $payload = [
            'interfaces' => [
                ['name' => 'br0', 'type' => 'bridge', 'state' => 'up', 'mtu' => 1500],
                ['name' => 'vlan10', 'type' => 'vlan', 'parent' => 'br0', 'vlan_id' => 10, 'state' => 'up'],
            ],
            'addresses' => [
                ['interface' => 'br0', 'cidr' => '198.51.100.1/24'],
            ],
            'routes' => [
                ['destination' => 'default', 'gateway' => '198.51.100.254', 'device' => 'br0', 'metric' => 10],
            ],
            'bridge_vlans' => [
                ['device' => 'br0', 'vid' => 10, 'self' => true],
            ],
            'forwarding' => [
                'ipv4' => true,
            ],
            'services' => [
                'dns' => [
                    'forwarders' => ['1.1.1.1'],
                    'search_domains' => ['corp.example'],
                ],
                'dhcp_servers' => [
                    [
                        'interface' => 'br0',
                        'start' => 100,
                        'limit' => 50,
                        'lease_time' => '12h',
                        'domain' => 'corp.example',
                        'dns_servers' => ['10.10.10.1'],
                    ],
                ],
                'pppoe_clients' => [
                    [
                        'name' => 'wan_pppoe',
                        'interface' => 'mgmt0',
                        'username' => 'uplink-user',
                        'password' => 'SecretPassword123!',
                        'service_name' => 'isp-service',
                        'default_route' => true,
                        'mtu' => 1492,
                    ],
                ],
            ],
            'firewall' => [
                'rules' => [
                    ['chain' => 'forward', 'action' => 'accept', 'in_interface' => 'br0', 'out_interface' => 'mgmt0'],
                ],
                'nat' => [
                    ['chain' => 'postrouting', 'action' => 'masquerade', 'source' => '198.51.100.0/24'],
                ],
            ],
        ];

        $response = $this->actingAs($user)->putJson(route('routers.desired-state.update', $routerNode), $payload);

        $response->assertOk()
            ->assertJsonPath('message', 'Desired routing state staged.')
            ->assertJsonPath('state.router.status', 'staged')
            ->assertJsonPath('state.desired.normalized.interfaces.0.name', 'mgmt0')
            ->assertJsonPath('state.desired.normalized.interfaces.1.name', 'br0')
            ->assertJsonPath('state.desired.normalized.addresses.1.cidr', '198.51.100.1/24')
            ->assertJsonPath('state.desired.normalized.bridge_vlans.0.vid', 10)
            ->assertJsonPath('state.desired.normalized.forwarding.ipv4', true)
            ->assertJsonPath('state.desired.normalized.services.dns.forwarders.0', '1.1.1.1')
            ->assertJsonPath('state.desired.normalized.services.dhcp_servers.0.interface', 'br0')
            ->assertJsonPath('state.desired.normalized.services.pppoe_clients.0.name', 'wan_pppoe')
            ->assertJsonPath('state.desired.normalized.services.pppoe_clients.0.has_password', true)
            ->assertJsonPath('state.desired.normalized.firewall.rules.0.chain', 'forward');

        $this->assertStringContainsString(
            "option password '***redacted***'",
            (string) $response->json('state.desired.runtime_preview.network'),
        );

        $routerNode->refresh();

        $this->assertSame('staged', $routerNode->status);
        $this->assertSame('bridge', data_get($routerNode->desired_configuration, 'interfaces.0.type'));
        $this->assertSame('198.51.100.1/24', data_get($routerNode->desired_configuration, 'addresses.0.cidr'));
        $this->assertSame(10, data_get($routerNode->desired_configuration, 'bridge_vlans.0.vid'));
        $this->assertTrue((bool) data_get($routerNode->desired_configuration, 'forwarding.ipv4'));
        $this->assertSame('1.1.1.1', data_get($routerNode->desired_configuration, 'services.dns.forwarders.0'));
        $this->assertSame('wan_pppoe', data_get($routerNode->desired_configuration, 'services.pppoe_clients.0.name'));
        $this->assertNull(data_get($routerNode->desired_configuration, 'services.pppoe_clients.0.password'));
        $this->assertSame('SecretPassword123!', data_get($routerNode->desired_secret_configuration, 'services.pppoe_clients.wan_pppoe.password'));
        $this->assertSame('forward', data_get($routerNode->desired_configuration, 'firewall.rules.0.chain'));
        $this->assertNotNull($routerNode->desired_revision_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'router.desired_state.updated',
            'status' => 'success',
        ]);
    }

    public function test_invalid_cidr_is_rejected_for_desired_state_update(): void
    {
        $routerNode = RouterNode::factory()->create();
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->putJson(route('routers.desired-state.update', $routerNode), [
            'addresses' => [
                ['interface' => 'br0', 'cidr' => '999.1.1.1/24'],
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['addresses.0.cidr']);
    }
}
