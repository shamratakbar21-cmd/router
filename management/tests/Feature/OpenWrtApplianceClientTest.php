<?php

namespace Tests\Feature;

use App\Exceptions\ApplianceTransportException;
use App\Models\RouterNode;
use App\Services\OpenWrtApplianceClient;
use App\Services\OpenWrtOverlayExporter;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class OpenWrtApplianceClientTest extends TestCase
{
    public function test_ssh_option_and_shell_characters_in_targets_are_rejected(): void
    {
        foreach ([['-oProxyCommand=id', '192.0.2.2'], ['nur-mgmt', 'host;id']] as [$user, $host]) {
            $router = RouterNode::factory()->make([
                'appliance_host' => $host,
                'appliance_ssh_user' => $user,
                'appliance_ssh_private_key' => 'test-key',
                'appliance_ssh_known_host' => 'test-pin',
            ]);
            try {
                (new OpenWrtApplianceClient(Mockery::mock(OpenWrtOverlayExporter::class), 0.1))->observeConfiguration($router);
                $this->fail('An unsafe transport target must be rejected.');
            } catch (ApplianceTransportException $exception) {
                $this->assertSame(ApplianceTransportException::KIND_COMMAND_REJECTED, $exception->kind);
            }
        }
    }

    public function test_a_hung_ssh_process_has_a_bounded_timeout(): void
    {
        $directory = storage_path('framework/testing/ssh-timeout');
        File::ensureDirectoryExists($directory);
        File::put($directory.'/ssh', "#!/bin/sh\nexec sleep 5\n");
        chmod($directory.'/ssh', 0755);
        $path = (string) getenv('PATH');
        putenv("PATH={$directory}:{$path}");
        $router = RouterNode::factory()->make([
            'appliance_host' => '192.0.2.2',
            'appliance_ssh_user' => 'nur-mgmt',
            'appliance_ssh_private_key' => 'test-key',
            'appliance_ssh_known_host' => 'test-pin',
        ]);
        try {
            (new OpenWrtApplianceClient(Mockery::mock(OpenWrtOverlayExporter::class), 0.1))->observeConfiguration($router);
            $this->fail('A hung transport must time out.');
        } catch (ApplianceTransportException $exception) {
            $this->assertSame('ssh_timeout', $exception->kind);
        } finally {
            putenv("PATH={$path}");
            File::deleteDirectory($directory);
        }
    }

    public function test_apply_configuration_uses_uppercase_scp_port_option_for_live_appliance_transfer(): void
    {
        $fixtureDirectory = storage_path('framework/testing/openwrt-appliance-client');
        $binDirectory = "{$fixtureDirectory}/bin";
        $scpLogPath = "{$fixtureDirectory}/scp.args";
        $sshLogPath = "{$fixtureDirectory}/ssh.args";
        $originalPath = (string) getenv('PATH');

        File::deleteDirectory($fixtureDirectory);
        File::ensureDirectoryExists($binDirectory);
        File::put(
            "{$binDirectory}/scp",
            "#!/bin/sh\nprintf '%s\n' \"\$@\" > ".escapeshellarg($scpLogPath)."\nexit 0\n",
        );
        File::put(
            "{$binDirectory}/ssh",
            "#!/bin/sh\nprintf '%s\n' \"\$@\" >> ".escapeshellarg($sshLogPath)."\nlast_arg=''\nfor arg in \"\$@\"; do\n  last_arg=\"\$arg\"\ndone\ncase \"\$last_arg\" in\n  *'nur-runtimectl apply'*)\n    printf '%s\n' '{\"links\":[],\"addresses\":[],\"routes\":[],\"bridge_vlans\":[],\"firewall\":{},\"forwarding\":{\"ipv4\":\"1\"},\"services\":{\"dnsmasq_running\":true,\"pppoe_interfaces\":[]},\"observed_at\":\"2026-11-01T08:30:00Z\"}'\n    ;;\nesac\nexit 0\n",
        );
        chmod("{$binDirectory}/scp", 0755);
        chmod("{$binDirectory}/ssh", 0755);
        putenv("PATH={$binDirectory}:{$originalPath}");

        $overlayExporter = Mockery::mock(OpenWrtOverlayExporter::class);
        $overlayExporter
            ->shouldReceive('export')
            ->once()
            ->andReturnUsing(function (RouterNode $_routerNode, string $targetDirectory): array {
                File::ensureDirectoryExists("{$targetDirectory}/etc/config");
                File::put("{$targetDirectory}/etc/config/network", "# test overlay\n");

                return [
                    'network' => "{$targetDirectory}/etc/config/network",
                ];
            });

        $client = new OpenWrtApplianceClient($overlayExporter);
        $routerNode = RouterNode::factory()->make([
            'namespace_name' => 'qemulab',
            'appliance_host' => '192.0.2.2',
            'appliance_ssh_port' => 2222,
            'appliance_ssh_user' => 'root',
            'appliance_ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nTRANSIENT-LAB-KEY\n-----END OPENSSH PRIVATE KEY-----\n",
            'appliance_ssh_known_host' => "192.0.2.2 ssh-ed25519 AAAATEST\n",
        ]);

        try {
            $response = $client->applyConfiguration(
                $routerNode,
                ['management' => ['interface' => 'eth0', 'cidr' => '192.0.2.2/24']],
                [],
                'test-operation',
            );
        } finally {
            putenv("PATH={$originalPath}");
        }

        $scpArgs = file("{$scpLogPath}", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $sshArgs = file("{$sshLogPath}", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertSame('2026-11-01T08:30:00Z', $response['observed_at']);
        $this->assertContains('-O', $scpArgs);
        $this->assertContains('-P', $scpArgs);
        $this->assertContains('2222', $scpArgs);
        $this->assertFalse(collect($scpArgs)->contains(
            fn (string $argument): bool => str_ends_with($argument, '/.'),
        ));
        $this->assertTrue(collect($scpArgs)->contains(
            fn (string $argument): bool => str_contains($argument, 'nurrouter-overlay-test-operation'),
        ));
        $this->assertTrue(collect($sshArgs)->contains(
            fn (string $argument): bool => str_contains($argument, '/tmp/nurrouter-overlay-test-operation'),
        ));
        $this->assertContains('-p', $sshArgs);
        $this->assertContains('2222', $sshArgs);

        File::deleteDirectory($fixtureDirectory);
    }
}
