<?php

namespace Tests\Feature;

use App\Exceptions\ApplianceTransportException;
use App\Models\RouterNode;
use App\Models\User;
use App\Services\ApplianceSshOperation;
use App\Services\OpenWrtApplianceClient;
use App\Services\OpenWrtOverlayExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class ApplianceTransportErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_host_key_enrollment_is_actionable(): void
    {
        $client = new OpenWrtApplianceClient(Mockery::mock(OpenWrtOverlayExporter::class));
        $router = RouterNode::factory()->make([
            'appliance_host' => '192.0.2.2',
            'appliance_ssh_user' => 'nur-mgmt',
            'appliance_ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nX\n-----END OPENSSH PRIVATE KEY-----\n",
            'appliance_ssh_known_host' => null,
        ]);

        try {
            $client->observeConfiguration($router);
            $this->fail('Expected ApplianceTransportException');
        } catch (ApplianceTransportException $exception) {
            $this->assertSame(ApplianceTransportException::KIND_NOT_ENROLLED, $exception->kind);
            $this->assertStringContainsString('Staged configuration was preserved', $exception->userMessage());
        }
    }

    public function test_ssh_timeout_is_classified_and_apply_preserves_staged_revision(): void
    {
        $fixtureDirectory = storage_path('framework/testing/appliance-transport-timeout');
        $binDirectory = "{$fixtureDirectory}/bin";
        $originalPath = (string) getenv('PATH');

        File::deleteDirectory($fixtureDirectory);
        File::ensureDirectoryExists($binDirectory);
        File::put("{$binDirectory}/ssh", "#!/bin/sh\necho 'ssh: connect to host 192.0.2.2 port 22: Connection timed out' >&2\nexit 255\n");
        File::put("{$binDirectory}/scp", "#!/bin/sh\necho 'ssh: connect to host 192.0.2.2 port 22: Connection timed out' >&2\nexit 255\n");
        chmod("{$binDirectory}/ssh", 0755);
        chmod("{$binDirectory}/scp", 0755);
        putenv("PATH={$binDirectory}:{$originalPath}");

        $user = User::factory()->admin()->create();
        $router = RouterNode::factory()->create([
            'appliance_host' => '192.0.2.2',
            'appliance_ssh_port' => 22,
            'appliance_ssh_user' => 'nur-mgmt',
            'appliance_ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nX\n-----END OPENSSH PRIVATE KEY-----\n",
            'appliance_ssh_known_host' => "192.0.2.2 ssh-ed25519 AAAATEST\n",
            'desired_configuration' => [
                'addresses' => [['interface' => 'eth1', 'cidr' => '10.10.10.9/24']],
                'interfaces' => [],
                'routes' => [],
                'bridge_vlans' => [],
                'forwarding' => ['ipv4' => true],
                'services' => ['dns' => ['forwarders' => [], 'search_domains' => []], 'dhcp_servers' => [], 'pppoe_clients' => []],
                'firewall' => [],
            ],
            'status' => 'staged',
        ]);

        $revision = $router->revisions()->create([
            'user_id' => $user->id,
            'revision_number' => 1,
            'desired_configuration' => $router->desired_configuration,
            'desired_secret_configuration' => [],
            'apply_status' => 'pending',
            'base_lock_version' => 0,
        ]);
        $router->forceFill([
            'desired_revision_id' => $revision->id,
            'current_revision_id' => $revision->id,
        ])->save();

        try {
            $response = $this->actingAs($user)->post(route('routers.apply', $router), [
                'expected_lock_version' => 0,
                'safe_mode_minutes' => 5,
            ]);
        } finally {
            putenv("PATH={$originalPath}");
        }

        $response->assertRedirect(route('routers.show', $router));
        $response->assertSessionHas('error');
        $response->assertSessionHas('error_kind', ApplianceTransportException::KIND_TIMEOUT);
        $response->assertSessionHas('staged_preserved', true);
        $response->assertSessionMissing('status');

        $router->refresh();
        $revision->refresh();
        $this->assertSame($revision->id, $router->desired_revision_id);
        $this->assertSame('error', $router->status);
        $this->assertSame('outcome-unknown', $revision->apply_status);
        $this->assertTrue($router->hasStagedConfiguration() || $router->desired_revision_id === $revision->id);

        File::deleteDirectory($fixtureDirectory);
    }

    public function test_typed_operations_reject_path_injection(): void
    {
        $this->expectException(ApplianceTransportException::class);

        ApplianceSshOperation::apply('/tmp/evil; rm -rf /', 'op-1', 60);
    }

    public function test_cross_router_authorization_blocks_non_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $router = RouterNode::factory()->create();

        $this->actingAs($user)
            ->post(route('routers.apply', $router), [
                'expected_lock_version' => 0,
                'safe_mode_minutes' => 5,
            ])
            ->assertForbidden();
    }

    public function test_host_key_mismatch_classification(): void
    {
        $exception = ApplianceTransportException::fromProcessFailure(
            'Host key verification failed.',
            255,
            'observe',
        );

        $this->assertSame(ApplianceTransportException::KIND_HOST_KEY_MISMATCH, $exception->kind);
    }

    public function test_pppoe_admission_exit_is_actionable(): void
    {
        $exception = ApplianceTransportException::fromProcessFailure(
            'PPPoE admission limit 50; staged config has 51. Established sessions are preserved; reduce profiles and re-apply.',
            20,
            'apply',
        );

        $this->assertSame(ApplianceTransportException::KIND_PPPOE_ADMISSION, $exception->kind);
        $this->assertStringContainsString('admission', strtolower($exception->userMessage()));
        $this->assertStringContainsString('Staged configuration was preserved', $exception->userMessage());
    }

    public function test_license_mutation_block_exit_is_actionable(): void
    {
        $exception = ApplianceTransportException::fromProcessFailure(
            'License policy blocks entitlement-gated configuration changes (allow_entitlement_mutations=false). Existing traffic and local recovery are preserved.',
            21,
            'apply',
        );

        $this->assertSame(ApplianceTransportException::KIND_LICENSE_MUTATION_BLOCKED, $exception->kind);
    }
}
