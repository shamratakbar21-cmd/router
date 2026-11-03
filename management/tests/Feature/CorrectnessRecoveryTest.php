<?php

namespace Tests\Feature;

use App\Exceptions\ApplianceTransportException;
use App\Models\LicensePlan;
use App\Models\RouterNode;
use App\Models\User;
use App\Services\Licensing\LicenseEnforcement;
use App\Services\Licensing\LicenseService;
use App\Services\OpenWrtApplianceClient;
use App\Services\RouterAgentClient;
use App\Services\RouterConfigurationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CorrectnessRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function router(bool $appliance = false): RouterNode
    {
        $router = RouterNode::factory()->create([
            'management_interface' => 'eth0', 'management_cidr' => '192.0.2.2/24',
            'desired_configuration' => ['management' => ['interface' => 'eth0', 'cidr' => '192.0.2.2/24']],
            'desired_secret_configuration' => [],
        ]);
        if ($appliance) {
            $router->update(['appliance_host' => '127.0.0.1', 'appliance_ssh_user' => 'nur-mgmt', 'appliance_ssh_private_key' => 'fixture', 'appliance_ssh_known_host' => 'fixture']);
        }
        app(RouterConfigurationManager::class)->createRevision($router);

        return $router->fresh();
    }

    public function test_unrelated_edit_retains_credentials_and_apply_receives_them_without_browser_disclosure(): void
    {
        $router = $this->router(true);
        $client = ['name' => 'wan', 'interface' => 'eth1', 'username' => 'alice'];
        $router->update(['desired_configuration' => [...$router->desired_configuration, 'services' => ['pppoe_clients' => [$client]]], 'desired_secret_configuration' => ['services' => ['pppoe_clients' => ['wan' => ['password' => 'private-sentinel']]]]]);
        $this->actingAs(User::factory()->admin()->create())->putJson(route('routers.desired-state.update', $router), ['services' => ['pppoe_clients' => [...[$client + ['password' => '']]]], 'routes' => []])->assertOk()->assertDontSee('private-sentinel');
        $node = $router->fresh();
        $this->assertSame('private-sentinel', $node->desired_secret_configuration['services']['pppoe_clients']['wan']['password']);
        $this->mock(OpenWrtApplianceClient::class)->shouldReceive('applyConfiguration')->once()->withArgs(function ($node, $config, $secrets) {
            return $secrets['services']['pppoe_clients']['wan']['password'] === 'private-sentinel';
        })->andReturn(['links' => []]);
        app(RouterConfigurationManager::class)->applyCurrentRevision($node);
        $this->assertSame('private-sentinel', $router->fresh()->applied_secret_configuration['services']['pppoe_clients']['wan']['password']);
        $this->get(route('routers.desired-state.edit', $router))->assertOk()->assertDontSee('private-sentinel');
    }

    public function test_credentials_can_be_replaced_explicitly_cleared_and_deleted_with_profile(): void
    {
        $router = $this->router();
        $this->actingAs(User::factory()->admin()->create());
        $profile = ['name' => 'wan', 'interface' => 'eth1', 'username' => 'alice'];
        foreach ([['replace', 'first-secret'], ['retain', ''], ['replace', ' second-secret '], ['clear', '']] as [$action, $password]) {
            $this->putJson(route('routers.desired-state.update', $router), ['services' => ['pppoe_clients' => [$profile + ['password_action' => $action, 'password' => $password]]]])->assertOk()->assertDontSee('first-secret')->assertDontSee('second-secret');
            $actual = $router->fresh()->desired_secret_configuration['services']['pppoe_clients']['wan']['password'] ?? null;
            $this->assertSame($action === 'clear' ? null : ($action === 'retain' ? 'first-secret' : $password), $actual);
        }
        $this->putJson(route('routers.desired-state.update', $router), ['services' => ['pppoe_clients' => [$profile + ['password' => 'third-secret']]]])->assertOk();
        $this->putJson(route('routers.desired-state.update', $router), ['services' => ['pppoe_clients' => []]])->assertOk();
        $this->assertSame([], $router->fresh()->desired_secret_configuration['services']['pppoe_clients']);
        $this->putJson(route('routers.desired-state.update', $router), ['services' => ['pppoe_clients' => [$profile + ['password_action' => 'replace', 'password' => '']]]])->assertUnprocessable();
    }

    public function test_staging_during_apply_is_preserved_and_conflicting_apply_and_rollback_are_rejected(): void
    {
        $router = $this->router(true);
        $oldId = $router->desired_revision_id;
        $newId = null;
        $this->mock(OpenWrtApplianceClient::class)->shouldReceive('applyConfiguration')->once()->andReturnUsing(function ($node) use (&$newId, $oldId) {
            $manager = app(RouterConfigurationManager::class);
            foreach (['apply', 'rollback'] as $action) {
                try {
                    if ($action === 'apply') {
                        $manager->applyCurrentRevision($node->fresh());
                    } else {
                        $manager->rollbackToRevision($node->fresh(), $node->revisions()->findOrFail($oldId));
                    }
                    $this->fail('Concurrent mutation was accepted.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('pending', strtolower($exception->getMessage()));
                }
            }
            $revision = $manager->updateDesiredState($node->fresh(), ['addresses' => [['interface' => 'eth1', 'cidr' => '198.51.100.1/24']]]);
            $newId = $revision->id;

            return ['links' => []];
        });
        app(RouterConfigurationManager::class)->applyCurrentRevision($router);
        $node = $router->fresh();
        $this->assertSame($oldId, $node->applied_revision_id);
        $this->assertSame($newId, $node->desired_revision_id);
        $this->assertSame('198.51.100.1/24', $node->desired_configuration['addresses'][0]['cidr']);
        $this->assertSame(1, $node->desiredRevision->base_lock_version);
    }

    public function test_lost_apply_response_keeps_owner_until_remote_success_is_reconciled(): void
    {
        $this->freezeTime();
        $router = $this->router(true);
        $mock = $this->mock(OpenWrtApplianceClient::class);
        $mock->shouldReceive('applyConfiguration')->once()->andThrow(new ApplianceTransportException(ApplianceTransportException::KIND_TIMEOUT, 'Connection lost after remote acceptance.'));
        try {
            app(RouterConfigurationManager::class)->applyCurrentRevision($router, safeModeMinutes: 5);
            $this->fail('Expected unknown outcome.');
        } catch (ApplianceTransportException) {
        }
        $node = $router->fresh();
        $owner = $node->pending_operation_uuid;
        $this->assertNotNull($owner);
        $mock->shouldReceive('observeConfiguration')->once()->andReturn(['apply_operations' => [$owner => 'applied']]);
        $this->travel(2)->minutes();
        $this->artisan('nur:reconcile-operations')->assertSuccessful();
        $node = $router->fresh();
        $this->assertNull($node->pending_operation_uuid);
        $this->assertSame($node->desired_revision_id, $node->applied_revision_id);
        $this->assertSame(now()->addMinutes(3)->timestamp, $node->safe_mode_confirm_by->timestamp);
    }

    public function test_terminated_worker_is_reconciled_but_missing_remote_identity_does_not_unlock_router(): void
    {
        $this->freezeTime();
        $router = $this->router(true);
        $revision = $router->desiredRevision;
        $owner = 'worker-killed-after-acceptance';
        $revision->update(['operation_uuid' => $owner, 'apply_status' => 'applying', 'diff_summary' => ['previous_applied_revision_id' => null]]);
        $router->update(['pending_operation_uuid' => $owner, 'reconciliation_deadline_at' => now()->subSecond()]);
        $mock = $this->mock(OpenWrtApplianceClient::class);
        $mock->shouldReceive('observeConfiguration')->once()->andReturn(['apply_operations' => []]);
        $this->artisan('nur:reconcile-operations')->assertSuccessful();
        $this->assertSame($owner, $router->fresh()->pending_operation_uuid);
        $this->assertSame('outcome-unknown', $router->fresh()->status);
        $this->travel(2)->minutes();
        $mock->shouldReceive('observeConfiguration')->once()->andReturn(['apply_operations' => [$owner => 'confirmed']]);
        $this->artisan('nur:reconcile-operations')->assertSuccessful();
        $this->assertNull($router->fresh()->pending_operation_uuid);
        $this->assertSame('confirmed', $router->fresh()->safe_mode_status);
    }

    public function test_stale_worker_cannot_finalize_a_new_owner(): void
    {
        $router = $this->router(true);
        $this->mock(OpenWrtApplianceClient::class)->shouldReceive('applyConfiguration')->once()->andReturnUsing(function ($node) {
            $node->update(['pending_operation_uuid' => 'new-owner-must-survive']);

            return ['links' => []];
        });
        try {
            app(RouterConfigurationManager::class)->applyCurrentRevision($router);
            $this->fail('A stale worker finalized.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Pending operation', $exception->getMessage());
        }
        $this->assertSame('new-owner-must-survive', $router->fresh()->pending_operation_uuid);
        $this->assertNull($router->fresh()->applied_revision_id);
    }

    public function test_safe_mode_recovery_and_confirmation_never_require_mutation_entitlement(): void
    {
        $this->freezeTime();
        foreach ([false, true] as $appliance) {
            $router = $this->router($appliance);
            $previous = $router->desiredRevision;
            $previous->update(['applied_configuration' => $previous->desired_configuration, 'apply_status' => 'applied', 'confirmed_at' => now()]);
            $next = $router->revisions()->create(['revision_number' => 2, 'desired_configuration' => ['management' => ['interface' => 'eth0', 'cidr' => '192.0.2.3/24']], 'operation_uuid' => 'safe-recovery-operation', 'diff_summary' => ['previous_applied_revision_id' => $previous->id]]);
            $router->update(['applied_revision_id' => $next->id, 'desired_revision_id' => $next->id, 'safe_mode_status' => 'pending_confirm', 'safe_mode_confirm_by' => now()->subMinute(), 'license_status' => 'revoked']);
            $enforcement = $this->mock(LicenseEnforcement::class);
            $enforcement->shouldNotReceive('assertMutationAllowed');
            if ($appliance) {
                $this->mock(OpenWrtApplianceClient::class)->shouldReceive('observeConfiguration')->once()->andReturn(['apply_operations' => ['safe-recovery-operation' => 'rolled_back']]);
            } else {
                $this->mock(RouterAgentClient::class)->shouldReceive('applyRoutingState')->once()->with($router->namespace_name, $previous->desired_configuration)->andReturn(['links' => []]);
            }
            app(RouterConfigurationManager::class)->recoverConfirmedConfiguration($router);
            $this->assertSame($previous->id, $router->fresh()->applied_revision_id);
            $this->assertSame($next->id, $router->fresh()->desired_revision_id);
            $router->refresh()->update(['applied_revision_id' => $next->id, 'safe_mode_status' => 'pending_confirm', 'safe_mode_confirm_by' => now()->addMinutes(5)]);
            if ($appliance) {
                $this->mock(OpenWrtApplianceClient::class)->shouldReceive('confirmSafeMode')->once()->withArgs(fn ($node, $operation) => $operation === 'safe-recovery-operation');
            }
            app(RouterConfigurationManager::class)->confirmSafeMode($router->fresh());
            $this->assertSame('confirmed', $router->fresh()->safe_mode_status);
            $this->app->forgetInstance(LicenseEnforcement::class);
        }
    }

    public function test_lost_confirmation_is_durably_reconciled_without_reapplying(): void
    {
        $this->freezeTime();
        $router = $this->router(true);
        $revision = $router->desiredRevision;
        $revision->update(['operation_uuid' => 'applied-confirmation-fixture']);
        $router->update(['applied_revision_id' => $revision->id, 'safe_mode_status' => 'pending_confirm', 'safe_mode_confirm_by' => now()->addMinutes(5)]);
        $mock = $this->mock(OpenWrtApplianceClient::class);
        $mock->shouldReceive('confirmSafeMode')->once()->andThrow(new ApplianceTransportException(ApplianceTransportException::KIND_TIMEOUT, 'Confirmation response lost.'));
        try {
            app(RouterConfigurationManager::class)->confirmSafeMode($router);
            $this->fail('Expected connection loss.');
        } catch (ApplianceTransportException) {
        }
        $this->assertNotNull($router->fresh()->pending_operation_uuid);
        $mock->shouldReceive('observeConfiguration')->once()->andReturn(['apply_operations' => ['applied-confirmation-fixture' => 'confirmed']]);
        $mock->shouldNotReceive('applyConfiguration');
        $this->travel(11)->minutes();
        $this->artisan('nur:reconcile-operations')->assertSuccessful();
        $this->assertNull($router->fresh()->pending_operation_uuid);
        $this->assertSame('confirmed', $router->fresh()->safe_mode_status);
    }

    public function test_revoked_license_blocks_new_changes_but_not_recorded_snapshot_recovery(): void
    {
        $router = $this->router();
        $previous = $router->desiredRevision;
        $previous->update(['confirmed_at' => now(), 'applied_configuration' => $previous->desired_configuration]);
        $next = $router->revisions()->create(['revision_number' => 2, 'desired_configuration' => ['management' => ['interface' => 'eth0', 'cidr' => '192.0.2.3/24']], 'diff_summary' => ['previous_applied_revision_id' => $previous->id]]);
        $router->update(['applied_revision_id' => $next->id, 'desired_revision_id' => $next->id, 'safe_mode_status' => 'pending_confirm', 'safe_mode_confirm_by' => now()->subMinute()]);
        $licenses = app(LicenseService::class);
        $licenses->ensureDefaultPlans();
        $issued = $licenses->issueActivationKey(LicensePlan::where('code', 'trial')->firstOrFail());
        $activation = $licenses->activateOnline($issued['plaintext'], 'recovery-revoked-device', $router);
        $licenses->revokeActivation($activation['activation'], 'Regression fixture');
        try {
            app(RouterConfigurationManager::class)->updateDesiredState($router->fresh(), ['addresses' => []]);
            $this->fail('A new licensed mutation was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('License blocks', $exception->getMessage());
        }
        $this->mock(RouterAgentClient::class)->shouldReceive('applyRoutingState')->once()->with($router->namespace_name, $previous->desired_configuration)->andReturn(['links' => []]);
        app(RouterConfigurationManager::class)->recoverConfirmedConfiguration($router->fresh());
        $this->assertSame($previous->id, $router->fresh()->applied_revision_id);
        $this->assertSame(2, $router->revisions()->count());
    }
}
