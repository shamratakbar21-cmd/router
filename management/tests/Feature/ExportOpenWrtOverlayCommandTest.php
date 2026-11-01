<?php

namespace Tests\Feature;

use App\Models\RouterNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExportOpenWrtOverlayCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exports_applied_router_configuration_into_openwrt_overlay_files(): void
    {
        $routerNode = RouterNode::factory()->create([
            'namespace_name' => 'nur-export-1',
            'management_interface' => 'mgmt0',
            'management_cidr' => '192.0.2.1/24',
            'applied_configuration' => [
                'namespace' => 'nur-export-1',
                'management' => [
                    'interface' => 'mgmt0',
                    'cidr' => '192.0.2.1/24',
                ],
                'services' => [
                    'dns' => [
                        'forwarders' => ['1.1.1.1'],
                    ],
                    'pppoe_clients' => [[
                        'name' => 'wan_pppoe',
                        'interface' => 'eth0',
                        'username' => 'uplink-user',
                        'default_route' => true,
                    ]],
                ],
            ],
            'applied_secret_configuration' => [
                'services' => [
                    'pppoe_clients' => [
                        'wan_pppoe' => ['password' => 'SecretPassword123!'],
                    ],
                ],
            ],
        ]);
        $targetDirectory = storage_path('framework/testing/export-openwrt-overlay');

        File::deleteDirectory($targetDirectory);

        $this->artisan('nur:export-openwrt-overlay', [
            'router' => $routerNode->namespace_name,
            '--source' => 'applied',
            '--target' => $targetDirectory,
        ])->assertExitCode(0);

        $this->assertFileExists("{$targetDirectory}/etc/config/network");
        $this->assertFileExists("{$targetDirectory}/etc/config/dhcp");
        $this->assertFileExists("{$targetDirectory}/etc/config/firewall");
        $this->assertFileExists("{$targetDirectory}/etc/nurrouter/overlay-manifest.json");
        $this->assertStringNotContainsString(
            'SecretPassword123!',
            (string) file_get_contents("{$targetDirectory}/etc/config/network"),
        );
        $this->assertStringContainsString(
            "list server '1.1.1.1'",
            (string) file_get_contents("{$targetDirectory}/etc/config/dhcp"),
        );
        $this->assertStringContainsString(
            "option name 'management'",
            (string) file_get_contents("{$targetDirectory}/etc/config/firewall"),
        );
        $this->assertStringContainsString(
            '"namespace": "nur-export-1"',
            (string) file_get_contents("{$targetDirectory}/etc/nurrouter/overlay-manifest.json"),
        );

        File::deleteDirectory($targetDirectory);
    }

    public function test_it_can_include_plaintext_secrets_for_transient_appliance_apply_payloads(): void
    {
        $routerNode = RouterNode::factory()->create([
            'namespace_name' => 'nur-export-3',
            'desired_configuration' => [
                'services' => [
                    'pppoe_clients' => [[
                        'name' => 'wan_pppoe',
                        'interface' => 'eth0',
                        'username' => 'uplink-user',
                    ]],
                ],
            ],
            'desired_secret_configuration' => [
                'services' => [
                    'pppoe_clients' => [
                        'wan_pppoe' => ['password' => 'TransientSecret123!'],
                    ],
                ],
            ],
        ]);
        $targetDirectory = storage_path('framework/testing/export-openwrt-overlay-with-secrets');

        File::deleteDirectory($targetDirectory);

        $this->artisan('nur:export-openwrt-overlay', [
            'router' => $routerNode->namespace_name,
            '--source' => 'desired',
            '--include-secrets' => '1',
            '--target' => $targetDirectory,
        ])->assertExitCode(0);

        $this->assertStringContainsString(
            "option password 'TransientSecret123!'",
            (string) file_get_contents("{$targetDirectory}/etc/config/network"),
        );

        File::deleteDirectory($targetDirectory);
    }

    public function test_it_rejects_export_when_requested_source_is_missing(): void
    {
        $routerNode = RouterNode::factory()->create([
            'namespace_name' => 'nur-export-2',
            'applied_configuration' => null,
        ]);

        $this->artisan('nur:export-openwrt-overlay', [
            'router' => $routerNode->namespace_name,
            '--source' => 'applied',
            '--target' => storage_path('framework/testing/export-openwrt-overlay-missing'),
        ])->assertExitCode(1);
    }
}
