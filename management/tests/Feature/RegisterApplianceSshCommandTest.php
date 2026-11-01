<?php

namespace Tests\Feature;

use App\Models\RouterNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RegisterApplianceSshCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_command_stores_appliance_ssh_material_from_files(): void
    {
        $routerNode = RouterNode::factory()->create([
            'namespace_name' => 'nur-lab-appliance',
            'appliance_host' => null,
            'appliance_ssh_user' => null,
            'appliance_ssh_private_key' => null,
            'appliance_ssh_known_host' => null,
        ]);
        $fixtureDirectory = storage_path('framework/testing/appliance-ssh');
        $privateKeyPath = "{$fixtureDirectory}/id_ed25519";
        $knownHostPath = "{$fixtureDirectory}/known_hosts";

        File::ensureDirectoryExists($fixtureDirectory);
        File::put($privateKeyPath, "-----BEGIN OPENSSH PRIVATE KEY-----\nTRANSIENT-LAB-KEY\n-----END OPENSSH PRIVATE KEY-----\n");
        File::put($knownHostPath, "192.0.2.2 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITestKnownHost\n");

        $this->artisan('nur:register-appliance-ssh', [
            'router' => $routerNode->namespace_name,
            'host' => '192.0.2.2',
            '--port' => 2222,
            '--user' => 'root',
            '--private-key-file' => $privateKeyPath,
            '--known-host-file' => $knownHostPath,
        ])->assertExitCode(0);

        $routerNode->refresh();

        $this->assertSame('192.0.2.2', $routerNode->appliance_host);
        $this->assertSame(2222, $routerNode->appliance_ssh_port);
        $this->assertSame('root', $routerNode->appliance_ssh_user);
        $this->assertSame("-----BEGIN OPENSSH PRIVATE KEY-----\nTRANSIENT-LAB-KEY\n-----END OPENSSH PRIVATE KEY-----\n", $routerNode->appliance_ssh_private_key);
        $this->assertSame("192.0.2.2 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITestKnownHost\n", $routerNode->appliance_ssh_known_host);

        File::deleteDirectory($fixtureDirectory);
    }

    public function test_noninteractive_host_key_replacement_requires_force_flag(): void
    {
        $routerNode = RouterNode::factory()->create([
            'namespace_name' => 'nur-lab-pin-replace',
            'appliance_host' => '192.0.2.2',
            'appliance_ssh_known_host' => "192.0.2.2 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOldPin\n",
        ]);
        $fixtureDirectory = storage_path('framework/testing/appliance-ssh-replace');
        $privateKeyPath = "{$fixtureDirectory}/id_ed25519";
        $knownHostPath = "{$fixtureDirectory}/known_hosts";
        File::ensureDirectoryExists($fixtureDirectory);
        File::put($privateKeyPath, "-----BEGIN OPENSSH PRIVATE KEY-----\nTRANSIENT\n-----END OPENSSH PRIVATE KEY-----\n");
        File::put($knownHostPath, "192.0.2.2 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINewPin\n");

        $this->artisan('nur:register-appliance-ssh', [
            'router' => $routerNode->namespace_name,
            'host' => '192.0.2.2',
            '--private-key-file' => $privateKeyPath,
            '--known-host-file' => $knownHostPath,
            '--no-interaction' => true,
        ])->assertExitCode(1);

        $this->assertStringContainsString('OldPin', (string) $routerNode->fresh()->appliance_ssh_known_host);

        $this->artisan('nur:register-appliance-ssh', [
            'router' => $routerNode->namespace_name,
            'host' => '192.0.2.2',
            '--private-key-file' => $privateKeyPath,
            '--known-host-file' => $knownHostPath,
            '--force-replace-host-key' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $this->assertStringContainsString('NewPin', (string) $routerNode->fresh()->appliance_ssh_known_host);
        File::deleteDirectory($fixtureDirectory);
    }

    public function test_register_without_known_host_file_is_rejected(): void
    {
        $routerNode = RouterNode::factory()->create([
            'namespace_name' => 'nur-lab-no-pin',
            'appliance_host' => null,
            'appliance_ssh_known_host' => null,
        ]);
        $fixtureDirectory = storage_path('framework/testing/appliance-ssh-nopin');
        $privateKeyPath = "{$fixtureDirectory}/id_ed25519";
        File::ensureDirectoryExists($fixtureDirectory);
        File::put($privateKeyPath, "-----BEGIN OPENSSH PRIVATE KEY-----\nTRANSIENT\n-----END OPENSSH PRIVATE KEY-----\n");

        $this->artisan('nur:register-appliance-ssh', [
            'router' => $routerNode->namespace_name,
            'host' => '192.0.2.2',
            '--private-key-file' => $privateKeyPath,
        ])->assertExitCode(1);

        $this->assertNull($routerNode->fresh()->appliance_host);
        File::deleteDirectory($fixtureDirectory);
    }

    public function test_clear_option_removes_stored_appliance_ssh_material(): void
    {
        $routerNode = RouterNode::factory()->create([
            'namespace_name' => 'nur-lab-appliance-clear',
            'appliance_host' => '192.0.2.2',
            'appliance_ssh_port' => 22,
            'appliance_ssh_user' => 'root',
            'appliance_ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nCLEAR-ME\n-----END OPENSSH PRIVATE KEY-----\n",
            'appliance_ssh_known_host' => "192.0.2.2 ssh-ed25519 AAAATEST\n",
        ]);

        $this->artisan('nur:register-appliance-ssh', [
            'router' => $routerNode->namespace_name,
            '--clear' => true,
        ])->assertExitCode(0);

        $routerNode->refresh();

        $this->assertNull($routerNode->appliance_host);
        $this->assertNull($routerNode->appliance_ssh_port);
        $this->assertNull($routerNode->appliance_ssh_user);
        $this->assertNull($routerNode->appliance_ssh_private_key);
        $this->assertNull($routerNode->appliance_ssh_known_host);
    }
}
