<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LicensePlan;
use App\Models\ObservedState;
use App\Models\RouterNode;
use App\Models\User;
use App\Services\AuditLogWriter;
use App\Services\ConfigurationBackupService;
use App\Services\Licensing\LicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Tests\TestCase;

class ConfigurationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function node(): RouterNode
    {
        return RouterNode::factory()->create(['desired_configuration' => [
            'interfaces' => [['name' => 'eth1', 'type' => 'ether']],
            'addresses' => [['interface' => 'eth1', 'cidr' => '198.51.100.1/24']],
            'services' => ['pppoe_clients' => [['name' => 'wan', 'interface' => 'eth1', 'username' => 'alice']]],
        ], 'desired_secret_configuration' => ['services' => ['pppoe_clients' => ['wan' => ['password' => 'backup-secret-sentinel']]]]]);
    }

    private function checksum(array $document): string
    {
        unset($document['integrity']);
        $document['integrity'] = ['alg' => 'sha256', 'value' => hash('sha256', json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];

        return json_encode($document);
    }

    public function test_authenticated_backup_restores_and_rejects_tampering_even_with_recomputed_checksum(): void
    {
        $router = $this->node();
        $service = app(ConfigurationBackupService::class);
        $export = $service->export($router, includeSecrets: true);
        $this->assertStringNotContainsString('backup-secret-sentinel', $export['json']);
        $this->assertTrue($service->preview($export['json'], $router)['ok']);
        $router->update(['desired_secret_configuration' => []]);
        $result = $service->restore($router->fresh(), $export['json'], confirm: true);
        $this->assertNotNull($result['revision_id']);
        $this->assertSame('backup-secret-sentinel', $router->fresh()->desired_secret_configuration['services']['pppoe_clients']['wan']['password']);
        $document = json_decode($export['json'], true);
        $raw = base64_decode($document['encrypted_payload']);
        $document['encrypted_payload'] = base64_encode(substr_replace($raw, 'X', 35, 1));
        $this->assertFalse($service->preview($this->checksum($document), $router)['ok']);
    }

    public function test_malformed_incompatible_missing_checksum_and_bad_configuration_are_rejected(): void
    {
        $router = $this->node();
        $service = app(ConfigurationBackupService::class);
        $export = $service->export($router);
        foreach (['{', 'null', '42', '[]', '{}'] as $bad) {
            $this->assertFalse($service->preview($bad, $router)['ok']);
        }
        $document = json_decode($export['json'], true);
        $document['desired_configuration']['addresses'][0]['cidr'] = '999.1.1.1/99';
        $this->assertFalse($service->preview(json_encode($document), $router)['ok']);
        $this->assertFalse($service->preview($this->checksum($document), $router)['ok']);
        $document = json_decode($export['json'], true);
        $document['format_version'] = 99;
        $this->assertFalse($service->preview($this->checksum($document), $router)['ok']);
        $document = json_decode($export['json'], true);
        unset($document['integrity']);
        $this->assertFalse($service->preview(json_encode($document), $router)['ok']);
        $document['desired_configuration']['interfaces'] = 'not a list';
        $this->assertFalse($service->preview($this->checksum($document), $router)['ok']);
        $document['desired_configuration']['interfaces'] = ['not an entry'];
        $this->assertFalse($service->preview($this->checksum($document), $router)['ok']);
    }

    public function test_backup_portability_requires_original_key_and_legacy_requires_explicit_import(): void
    {
        $router = $this->node();
        $service = app(ConfigurationBackupService::class);
        $public = $service->export($router)['json'];
        $encrypted = $service->export($router, includeSecrets: true)['json'];
        $originalKey = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('b', 32))]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $this->assertFalse($service->preview($encrypted, $router)['ok']);
        $this->assertTrue($service->preview($public, $router)['ok']);
        config(['app.key' => $originalKey]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $legacy = json_decode($public, true);
        $legacy['format_version'] = 1;
        $legacy = $this->checksum($legacy);
        $this->assertFalse($service->preview($legacy, $router)['ok']);
        $this->assertTrue($service->preview($legacy, $router, allowLegacy: true)['ok']);
    }

    public function test_failed_restore_rolls_back_configuration_revision_and_secrets_without_snapshot_files(): void
    {
        $router = $this->node();
        $export = app(ConfigurationBackupService::class)->export($router, includeSecrets: true);
        $router->update(['desired_configuration' => ['addresses' => []]]);
        $before = $router->fresh()->getAttributes();
        $audit = $this->mock(AuditLogWriter::class);
        $audit->shouldReceive('write')->andReturnUsing(function ($action) {
            if ($action === 'router.backup.restored') {
                throw new RuntimeException('Injected restore audit failure');
            }

            return new AuditLog;
        });
        try {
            app(ConfigurationBackupService::class)->restore($router->fresh(), $export['json'], confirm: true);
            $this->fail('Expected restore failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected restore audit failure', $exception->getMessage());
        }
        $this->assertSame($before, $router->fresh()->getAttributes());
        $this->assertSame(0, $router->revisions()->count());
        $this->assertSame([], glob(storage_path('app/nurrouter/restore-rollback/*.json')) ?: []);
    }

    public function test_login_is_throttled_and_unauthorized_dashboard_does_not_disclose_audit_data(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), ['email' => 'brute@example.invalid', 'password' => 'bad'])->assertRedirect();
        }
        $this->post(route('login.store'), ['email' => 'brute@example.invalid', 'password' => 'bad'])->assertTooManyRequests();
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))->assertOk();
    }

    public function test_old_observation_is_marked_stale(): void
    {
        $this->freezeTime();
        $router = $this->node();
        ObservedState::factory()->create(['router_node_id' => $router->id, 'recorded_at' => now()->subMinutes(5)]);
        $this->actingAs(User::factory()->admin()->create())->get(route('routers.show', $router))->assertOk()->assertSee('Stale data');
    }

    public function test_unsupported_settings_return_validation_errors_without_staging_partial_configuration(): void
    {
        $router = $this->node();
        $before = $router->desired_configuration;
        $this->actingAs(User::factory()->admin()->create())->putJson(route('routers.desired-state.update', $router), ['forwarding' => ['ipv4' => false]])
            ->assertUnprocessable()->assertJsonValidationErrors('configuration');
        $this->assertSame($before, $router->fresh()->desired_configuration);
        $this->assertSame(0, $router->revisions()->count());
    }

    public function test_router_inventory_limit_is_distinct_from_activation_key_seats(): void
    {
        $admin = User::factory()->admin()->create();
        $licenses = app(LicenseService::class);
        $licenses->ensureDefaultPlans();
        $router = $this->node();
        $key = $licenses->issueActivationKey(LicensePlan::where('code', 'trial')->firstOrFail(), $admin, maxActivations: 10);
        $licenses->activateOnline($key['plaintext'], 'inventory-limit-device', $router);
        $this->actingAs($admin)->postJson(route('routers.store'), [
            'name' => 'Second router', 'namespace_name' => 'second-router', 'management_interface' => 'eth0', 'management_cidr' => '192.0.2.4/24',
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame(1, RouterNode::count());
        $this->assertSame(10, $key['key']->max_activations);
    }

    public function test_zero_router_limit_allows_inventory_growth(): void
    {
        $admin = User::factory()->admin()->create();
        $licenses = app(LicenseService::class);
        $licenses->ensureDefaultPlans();
        $router = $this->node();
        $issued = $licenses->issueActivationKey(LicensePlan::where('code', 'enterprise')->firstOrFail(), $admin);
        $licenses->activateOnline($issued['plaintext'], 'unlimited-inventory-device', $router);
        $this->actingAs($admin)->post(route('routers.store'), ['name' => 'Additional router', 'namespace_name' => 'unlimited-router', 'management_interface' => 'eth0', 'management_cidr' => '192.0.2.4/24'])->assertRedirect();
        $this->assertSame(2, RouterNode::count());
    }
}
