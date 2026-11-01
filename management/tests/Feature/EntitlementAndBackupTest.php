<?php

namespace Tests\Feature;

use App\Models\LicensePlan;
use App\Models\RouterNode;
use App\Models\User;
use App\Services\ConfigurationBackupService;
use App\Services\Licensing\CapabilityRegistry;
use App\Services\Licensing\EntitlementGate;
use App\Services\Licensing\LicenseService;
use App\Services\RouterConfigurationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class EntitlementAndBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(LicenseService::class)->ensureDefaultPlans();
    }

    public function test_capability_registry_lists_only_supported_features_as_enforceable(): void
    {
        $registry = $this->app->make(CapabilityRegistry::class);
        $supported = $registry->supportedFeatureCodes();

        $this->assertContains('shaping', $supported);
        $this->assertContains('pppoe', $supported);
        $this->assertNotContains('ha_export', $supported);
        $this->assertNotContains('priority_support', $supported);
    }

    public function test_trial_plan_rejects_shaping_rates_and_excess_pppoe_profiles(): void
    {
        $admin = User::factory()->admin()->create();
        $router = RouterNode::factory()->create(['namespace_name' => 'nur-ent-1']);
        $licenses = $this->app->make(LicenseService::class);
        $issued = $licenses->issueActivationKey(
            LicensePlan::query()->where('code', 'trial')->firstOrFail(),
            $admin,
        );
        $licenses->activateOnline($issued['plaintext'], 'dev-ent-1', $router, null, 'n-ent-1', $admin);

        $manager = $this->app->make(RouterConfigurationManager::class);

        try {
            $manager->updateDesiredState($router->fresh(), [
                'interfaces' => [],
                'addresses' => [],
                'routes' => [],
                'bridge_vlans' => [],
                'services' => [
                    'pppoe_clients' => [
                        ['name' => 'wan1', 'download_kbps' => 1000, 'upload_kbps' => 100],
                    ],
                ],
                'forwarding' => [],
                'firewall' => [],
            ], [], $admin);
            $this->fail('Expected shaping entitlement failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('shaping', $exception->getMessage());
        }

        // Trial max_pppoe_sessions = 50; build 51 profiles without shaping rates.
        $profiles = [];
        for ($i = 0; $i < 51; $i++) {
            $profiles[] = ['name' => 'p'.$i];
        }

        try {
            $manager->updateDesiredState($router->fresh(), [
                'interfaces' => [],
                'addresses' => [],
                'routes' => [],
                'bridge_vlans' => [],
                'services' => ['pppoe_clients' => $profiles],
                'forwarding' => [],
                'firewall' => [],
            ], [], $admin);
            $this->fail('Expected session limit failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('admission limit', $exception->getMessage());
        }
    }

    public function test_standard_plan_allows_shaping_within_session_limit(): void
    {
        $admin = User::factory()->admin()->create();
        $router = RouterNode::factory()->create(['namespace_name' => 'nur-ent-2']);
        $licenses = $this->app->make(LicenseService::class);
        $issued = $licenses->issueActivationKey(
            LicensePlan::query()->where('code', 'standard')->firstOrFail(),
            $admin,
        );
        $licenses->activateOnline($issued['plaintext'], 'dev-ent-2', $router, null, 'n-ent-2', $admin);

        $revision = $this->app->make(RouterConfigurationManager::class)->updateDesiredState(
            $router->fresh(),
            [
                'interfaces' => [],
                'addresses' => [],
                'routes' => [],
                'bridge_vlans' => [],
                'services' => [
                    'pppoe_clients' => [
                        ['name' => 'wan1', 'download_kbps' => 2000, 'upload_kbps' => 500],
                    ],
                ],
                'forwarding' => ['ipv4' => true],
                'firewall' => [],
            ],
            [],
            $admin,
        );

        $this->assertNotNull($revision->id);
        $this->assertArrayHasKey('license_entitlements', $router->fresh()->desired_configuration);
        $this->assertSame(250, $router->fresh()->desired_configuration['license_entitlements']['max_pppoe_sessions']);
    }

    public function test_backup_export_omits_device_identity_and_restore_preview_validates(): void
    {
        $admin = User::factory()->admin()->create();
        $router = RouterNode::factory()->create([
            'namespace_name' => 'nur-bak-1',
            'device_id' => 'should-not-export',
            'desired_configuration' => [
                'interfaces' => [['name' => 'eth0', 'type' => 'ether']],
                'addresses' => [],
                'routes' => [],
                'services' => ['pppoe_clients' => []],
                'firewall' => [],
            ],
        ]);

        $backups = $this->app->make(ConfigurationBackupService::class);
        $exported = $backups->export($router, $admin, includeSecrets: false);
        $document = json_decode($exported['json'], true);

        $this->assertSame('nurrouter-config-backup', $document['format']);
        $this->assertArrayNotHasKey('device_id', $document['source']);
        $this->assertArrayNotHasKey('license', $document);

        $preview = $backups->preview($exported['json'], $router);
        $this->assertTrue($preview['ok']);
        $this->assertSame(1, $preview['preview']['interfaces']);
    }

    public function test_appliance_entitlement_snapshot_marks_admission_policy(): void
    {
        $router = RouterNode::factory()->create(['namespace_name' => 'nur-ent-3']);
        $snapshot = $this->app->make(EntitlementGate::class)->applianceEntitlementSnapshot($router);

        $this->assertSame('admission_of_new_sessions', $snapshot['session_limit_governs']);
        $this->assertTrue($snapshot['preserve_established_on_restrict']);
    }
}
