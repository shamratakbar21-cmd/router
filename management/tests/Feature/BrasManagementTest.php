<?php

namespace Tests\Feature;

use App\Models\ObservedState;
use App\Models\RouterNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrasManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_configuration_requires_authorized_admin_and_never_exposes_secrets(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('routers.store'), ['name' => 'Access lab', 'namespace_name' => 'access-lab', 'management_interface' => 'eth0', 'management_cidr' => '192.0.2.1/24']);
        $router = RouterNode::query()->firstOrFail();
        $config = ['enabled' => true, 'access_interfaces' => ['eth1.100'], 'auth_mode' => 'local', 'gateway' => '10.55.0.1', 'max_sessions' => 2, 'pools' => [['name' => 'basic', 'start' => '10.55.0.2', 'end' => '10.55.0.3']], 'profiles' => [['name' => 'basic', 'pool' => 'basic', 'down_kbit' => 1024, 'up_kbit' => 512]], 'subscribers' => [['username' => 'alice', 'password' => 'private-test-pass', 'profile' => 'basic']]];
        $this->putJson(route('routers.bras.update', $router), ['configuration' => json_encode($config)])->assertOk()->assertDontSee('private-test-pass');
        $router->refresh();
        $this->assertStringNotContainsString('private-test-pass', json_encode($router->desired_configuration));
        $this->assertStringNotContainsString('private-test-pass', $router->getRawOriginal('desired_secret_configuration'));
        $this->assertSame('private-test-pass', data_get($router->desired_secret_configuration, 'services.pppoe_server.subscribers.alice'));
        $this->get(route('routers.bras.edit', $router))->assertOk()->assertDontSee('private-test-pass')->assertSee('WAN PPPoE client');
        $this->actingAs(User::factory()->create())->putJson(route('routers.bras.update', $router), ['configuration' => json_encode($config)])->assertForbidden();
    }

    public function test_management_binding_and_unsafe_config_are_rejected_without_flashing_secrets(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post(route('routers.store'), ['name' => 'Access', 'namespace_name' => 'access-two', 'management_interface' => 'eth0', 'management_cidr' => '192.0.2.1/24']);
        $router = RouterNode::query()->firstOrFail();
        $this->putJson(route('routers.bras.update', $router), ['configuration' => '{"enabled":true,"access_interfaces":["eth1\\ninterface=eth0"],"subscribers":[{"password":"secret-test"}]}'])->assertUnprocessable()->assertDontSee('secret-test');
        $this->assertNull(session('_old_input'));
    }

    public function test_observed_server_sessions_are_searchable_sorted_paginated_and_escaped_at_1000_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('routers.store'), ['name' => 'Scale lab', 'namespace_name' => 'scale-lab', 'management_interface' => 'eth0', 'management_cidr' => '192.0.2.1/24']);
        $router = RouterNode::query()->firstOrFail();
        $lines = [];
        for ($index = 1; $index <= 1000; $index++) {
            $lines[] = "nurbs{$index} | load{$index} | 00:00:00:00:00:00 | 10.70.0.2 | | pppoe | | active | 00:01:00";
        }
        ObservedState::factory()->for($router)->create(['state' => ['services' => ['pppoe_server' => ['sessions_table' => implode("\n", $lines), 'accounting' => '{"pending":0}']]], 'recorded_at' => now()]);
        $this->get(route('routers.bras.edit', $router))->assertOk()->assertSee('1000 matching sessions')->assertSee('load1')->assertDontSee('load1000</td>', false);
        $this->get(route('routers.bras.edit', $router).'?page=40')->assertOk()->assertSee('load1000</td>', false)->assertSee('page 40 of 40');
        $this->get(route('routers.bras.edit', $router).'?q=load1000&sort=username')->assertOk()->assertSee('1 matching sessions')->assertSee('load1000</td>', false)->assertDontSee('load999</td>', false);
    }
}
