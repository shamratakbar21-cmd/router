<?php

namespace Tests\Feature;

use App\Models\ObservedState;
use App\Models\RouterNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouterDesktopTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_gets_desktop_with_live_neighbor_data_and_no_ssh_secret(): void
    {
        $user = User::factory()->admin()->create();
        $router = RouterNode::factory()->create(['appliance_ssh_private_key' => 'private-key-sentinel']);
        ObservedState::factory()->for($router)->create([
            'state' => [
                'neighbors' => [['dst' => '192.168.77.124', 'dev' => 'eth1', 'lladdr' => '08:00:27:50:10:03', 'state' => ['REACHABLE']]],
            ],
        ]);

        $this->actingAs($user)->get(route('routers.show', $router))
            ->assertOk()
            ->assertSee('router-desktop-state', false)
            ->assertSee('192.168.77.124')
            ->assertSee('Apply staged configuration')
            ->assertSee('IP')
            ->assertDontSee('private-key-sentinel')
            ->assertDontSee('$shellRouter');

        $this->getJson(route('routers.state.show', $router))
            ->assertOk()
            ->assertJsonPath('observed.raw.neighbors.0.dev', 'eth1');
    }

    public function test_workspace_scope_comes_from_authenticated_user_and_authorized_router(): void
    {
        $firstUser = User::factory()->admin()->create();
        $secondUser = User::factory()->admin()->create();
        $firstRouter = RouterNode::factory()->create();
        $secondRouter = RouterNode::factory()->create();

        foreach ([[$firstUser, $firstRouter], [$firstUser, $secondRouter], [$secondUser, $firstRouter]] as [$user, $router]) {
            $response = $this->actingAs($user)->get(route('routers.show', $router).'?userId=999&routerId=999')->assertOk();
            preg_match('/<script type="application\/json" id="router-desktop-options">(.*?)<\/script>/s', $response->getContent(), $matches);
            $options = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame($user->id, $options['userId']);
            $this->assertSame($router->id, $options['routerId']);
            $this->assertSame(route('routers.state.show', $router), $options['stateUrl']);
        }
    }

    public function test_unprivileged_account_cannot_open_another_router_desktop(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $router = RouterNode::factory()->create();

        $this->actingAs($user)->get(route('routers.show', $router))->assertForbidden();
    }

    public function test_existing_configuration_and_recovery_pages_still_render(): void
    {
        $user = User::factory()->admin()->create();
        $router = RouterNode::factory()->create();

        foreach (['routers.desired-state.edit', 'routers.backup.show', 'routers.edit'] as $route) {
            $this->actingAs($user)->get(route($route, $router))->assertOk();
        }
    }

    public function test_desktop_preserves_authentication_boundary(): void
    {
        $router = RouterNode::factory()->create();
        $this->get(route('routers.show', $router))->assertRedirect(route('login'));
    }
}
