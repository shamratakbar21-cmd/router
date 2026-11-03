<?php

namespace App\Services;

use App\Exceptions\ApplianceTransportException;
use App\Models\ConfigurationRevision;
use App\Models\ObservedState;
use App\Models\RouterNode;
use App\Models\User;
use App\Services\Licensing\EntitlementGate;
use App\Services\Licensing\LicenseEnforcement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class RouterConfigurationManager
{
    public function __construct(
        private readonly RouterAgentClient $agentClient,
        private readonly OpenWrtApplianceClient $applianceClient,
        private readonly AuditLogWriter $auditLogWriter,
        private readonly LicenseEnforcement $licenseEnforcement,
        private readonly EntitlementGate $entitlementGate,
    ) {}

    public function createRevision(RouterNode $routerNode, ?User $user = null, ?int $rollbackOfRevisionId = null): ConfigurationRevision
    {
        $this->licenseEnforcement->assertMutationAllowed($routerNode);

        $revision = DB::transaction(function () use ($rollbackOfRevisionId, $routerNode, $user): ConfigurationRevision {
            /** @var RouterNode $lockedRouterNode */
            $lockedRouterNode = RouterNode::query()
                ->lockForUpdate()
                ->findOrFail($routerNode->getKey());

            $desiredConfiguration = $this->desiredConfigurationFor($lockedRouterNode);
            $desiredSecretConfiguration = $this->desiredSecretConfigurationFor($lockedRouterNode);
            $previousRevision = $lockedRouterNode->revisions()->latest('revision_number')->first();

            $revision = $lockedRouterNode->revisions()->create([
                'user_id' => $user?->id,
                'revision_number' => (int) ($previousRevision?->revision_number ?? 0) + 1,
                'base_lock_version' => $lockedRouterNode->configuration_lock_version,
                'desired_configuration' => $desiredConfiguration,
                'desired_secret_configuration' => $desiredSecretConfiguration,
                'diff_summary' => [
                    'previous' => $previousRevision?->desired_configuration,
                    'current' => $desiredConfiguration,
                ],
                'rollback_of_revision_id' => $rollbackOfRevisionId,
            ]);

            $lockedRouterNode->forceFill([
                'desired_configuration' => $desiredConfiguration,
                'desired_secret_configuration' => $desiredSecretConfiguration,
                'desired_revision_id' => $revision->id,
                'current_revision_id' => $revision->id,
                'status' => 'staged',
            ])->save();

            return $revision;
        }, attempts: 5);

        $this->auditLogWriter->write(
            'router.revision.created',
            "Created revision {$revision->revision_number} for {$routerNode->namespace_name}.",
            $routerNode,
            $user,
            metadata: ['revision_id' => $revision->id],
        );

        return $revision;
    }

    /**
     * @param  array<string, mixed>  $desiredState
     * @param  array<string, mixed>  $secretState
     */
    public function updateDesiredState(
        RouterNode $routerNode,
        array $desiredState,
        array $secretState = [],
        ?User $user = null,
    ): ConfigurationRevision {
        $revision = DB::transaction(function () use ($routerNode, $desiredState, $secretState, $user): ConfigurationRevision {
            $routerNode = RouterNode::query()->lockForUpdate()->findOrFail($routerNode->id);
            $this->entitlementGate->assertDesiredStateAllowed($routerNode, $desiredState, $secretState);
            try {
                app(OpenWrtRuntimeConfigRenderer::class)->render([...$desiredState, 'management' => ['interface' => $routerNode->management_interface, 'cidr' => $routerNode->management_cidr]], $secretState);
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['configuration' => $exception->getMessage()]);
            }

            $routerNode->forceFill([
                'desired_configuration' => [
                    ...($routerNode->desired_configuration ?? []),
                    'interfaces' => array_values($desiredState['interfaces'] ?? []),
                    'addresses' => array_values($desiredState['addresses'] ?? []),
                    'routes' => array_values($desiredState['routes'] ?? []),
                    'bridge_vlans' => array_values($desiredState['bridge_vlans'] ?? []),
                    'static_neighbors' => array_values($desiredState['static_neighbors'] ?? $routerNode->desired_configuration['static_neighbors'] ?? []),
                    'services' => [
                        ...($desiredState['services'] ?? []),
                        'dhcp_reservations' => data_get($desiredState, 'services.dhcp_reservations', data_get($routerNode->desired_configuration, 'services.dhcp_reservations', [])),
                        'ipv6' => data_get($desiredState, 'services.ipv6', data_get($routerNode->desired_configuration, 'services.ipv6', [])),
                        'wireguard' => data_get($desiredState, 'services.wireguard', data_get($routerNode->desired_configuration, 'services.wireguard', [])),
                        'pppoe_server' => data_get($desiredState, 'services.pppoe_server', data_get($routerNode->desired_configuration, 'services.pppoe_server', ['enabled' => false])),
                    ],
                    'forwarding' => $desiredState['forwarding'] ?? [],
                    'firewall' => $desiredState['firewall'] ?? [],
                    'license_entitlements' => $this->entitlementGate->applianceEntitlementSnapshot($routerNode),
                ],
                'desired_secret_configuration' => [
                    ...($routerNode->desired_secret_configuration ?? []),
                    'wireguard' => $secretState['wireguard'] ?? $routerNode->desired_secret_configuration['wireguard'] ?? [],
                    'services' => [
                        'pppoe_clients' => $this->mergePppoeCredentials($routerNode, $desiredState, $secretState),
                        'pppoe_server' => data_get($secretState, 'services.pppoe_server', data_get($routerNode->desired_secret_configuration, 'services.pppoe_server', [])),
                    ],
                ],
            ])->save();

            return $this->createRevision($routerNode, $user);

        }, attempts: 5);

        $this->auditLogWriter->write(
            'router.desired_state.updated',
            "Updated desired routing state for {$routerNode->namespace_name}.",
            $routerNode,
            $user,
            metadata: ['revision_id' => $revision->id],
        );

        return $revision;
    }

    private function mergePppoeCredentials(RouterNode $router, array $desired, array $secrets): array
    {
        $previous = $router->desired_secret_configuration['services']['pppoe_clients'] ?? [];
        $provided = $secrets['services']['pppoe_clients'] ?? [];
        $actions = $secrets['password_actions'] ?? [];
        $result = [];
        foreach ($desired['services']['pppoe_clients'] ?? [] as $client) {
            $name = $client['name'];
            $password = $provided[$name]['password'] ?? '';
            $action = $actions[$name] ?? ($password !== '' ? 'replace' : 'retain');
            if ($action === 'clear') {
                continue;
            }
            if ($action === 'replace' && $password === '') {
                throw new RuntimeException('Replacement PPPoE password is required.');
            }
            if ($action === 'replace') {
                $result[$name] = ['password' => $password];
            } elseif (isset($previous[$name])) {
                $result[$name] = $previous[$name];
            }
        }

        return $result;
    }

    public function applyCurrentRevision(
        RouterNode $routerNode,
        ?User $user = null,
        ?int $expectedLockVersion = null,
        ?int $safeModeMinutes = null,
        ?int $requestedRevisionId = null,
    ): ObservedState {
        $this->licenseEnforcement->assertMutationAllowed($routerNode);

        [$routerNode, $revision, $operationUuid, $safeModeMinutes] = DB::transaction(
            function () use ($expectedLockVersion, $routerNode, $safeModeMinutes, $requestedRevisionId): array {
                /** @var RouterNode $lockedRouterNode */
                $lockedRouterNode = RouterNode::query()
                    ->lockForUpdate()
                    ->findOrFail($routerNode->getKey());

                if ($lockedRouterNode->pending_operation_uuid !== null || $lockedRouterNode->safe_mode_status === 'pending_confirm') {
                    throw new RuntimeException('Another operation or Safe Mode confirmation is pending; reconcile it first.');
                }

                $desiredRevisionId = $requestedRevisionId ?? $lockedRouterNode->desired_revision_id ?? $lockedRouterNode->current_revision_id;

                /** @var ConfigurationRevision $revision */
                $revision = $lockedRouterNode->revisions()
                    ->whereKey($desiredRevisionId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $expectedLockVersion !== null
                    && $expectedLockVersion !== $lockedRouterNode->configuration_lock_version
                ) {
                    throw new RuntimeException('Configuration changed before this revision was applied.');
                }

                if (
                    $revision->base_lock_version !== $lockedRouterNode->configuration_lock_version
                    && $lockedRouterNode->applied_revision_id !== $revision->id
                ) {
                    $revision->forceFill([
                        'apply_status' => 'stale',
                        'stale_at' => now(),
                        'failure_reason' => 'The staged revision no longer matches the applied lock version.',
                    ])->save();

                    $lockedRouterNode->forceFill([
                        'status' => 'stale',
                    ])->save();

                    throw new RuntimeException('The staged revision is stale and must be recreated.');
                }

                try {
                    app(OpenWrtRuntimeConfigRenderer::class)->render($revision->desired_configuration, $revision->desired_secret_configuration ?? []);
                } catch (\InvalidArgumentException $exception) {
                    throw new RuntimeException($exception->getMessage(), 0, $exception);
                }

                $operationUuid = (string) Str::uuid();

                $summary = $revision->diff_summary ?? [];
                $summary['previous_applied_revision_id'] = $lockedRouterNode->applied_revision_id;
                $summary['operation_lock_version'] = $lockedRouterNode->configuration_lock_version;
                $revision->forceFill([
                    'diff_summary' => $summary,
                    'apply_status' => 'applying',
                    'operation_uuid' => $operationUuid,
                    'confirm_by' => $safeModeMinutes ? now()->addMinutes($safeModeMinutes) : null,
                    'confirmed_at' => null,
                    'failure_reason' => null,
                    'stale_at' => null,
                ])->save();

                $claimed = RouterNode::query()->whereKey($lockedRouterNode->id)
                    ->whereNull('pending_operation_uuid')
                    ->where('configuration_lock_version', $lockedRouterNode->configuration_lock_version)
                    ->update(['pending_operation_uuid' => $operationUuid, 'reconciliation_deadline_at' => now()->addSeconds(600), 'status' => 'applying']);
                if ($claimed !== 1) {
                    throw new RuntimeException('Another operation owns this router.');
                }
                $lockedRouterNode->refresh();

                return [$lockedRouterNode, $revision, $operationUuid, $safeModeMinutes];
            },
            attempts: 5,
        );

        $configurationApplied = ! $this->configurationAlreadyApplied($routerNode, $revision);

        try {
            if ($routerNode->usesApplianceConnection()) {
                $response = $configurationApplied
                    ? $this->applianceClient->applyConfiguration(
                        $routerNode,
                        $revision->desired_configuration,
                        $revision->desired_secret_configuration ?? [],
                        $operationUuid,
                        $safeModeMinutes,
                    )
                    : $this->applianceClient->observeConfiguration($routerNode);
            } else {
                $response = $configurationApplied
                    ? $this->agentClient->applyRoutingState(
                        $routerNode->namespace_name,
                        $revision->desired_configuration,
                    )
                    : $this->agentClient->observeRoutingState($routerNode->namespace_name);
            }
        } catch (Throwable $throwable) {
            $this->finalizeFailedApply($routerNode, $revision, $operationUuid, $throwable, $user);

            throw $throwable;
        }

        return $this->finalizeSuccessfulApply(
            $routerNode,
            $revision,
            $operationUuid,
            $response,
            $safeModeMinutes,
            $user,
            $configurationApplied,
        );
    }

    public function confirmSafeMode(RouterNode $routerNode, ?User $user = null): void
    {
        [$router, $revision, $owner] = DB::transaction(function () use ($routerNode): array {
            $router = RouterNode::query()->lockForUpdate()->findOrFail($routerNode->id);
            if ($router->pending_operation_uuid || $router->safe_mode_confirm_by?->isPast() || $router->safe_mode_status !== 'pending_confirm' || ! $router->applied_revision_id) {
                throw new RuntimeException('There is no pending Safe Mode session to confirm.');
            }
            $revision = $router->appliedRevision()->firstOrFail();
            $owner = (string) Str::uuid();
            $revision->forceFill(['diff_summary' => [...($revision->diff_summary ?? []), 'confirmation_owner' => $owner]])->save();
            $router->forceFill(['pending_operation_uuid' => $owner, 'reconciliation_deadline_at' => now()->addMinutes(10)])->save();

            return [$router, $revision, $owner];
        }, attempts: 5);
        // No database transaction spans the SSH call. Unknown results remain owned.
        if ($router->usesApplianceConnection()) {
            $this->applianceClient->confirmSafeMode($router, $revision->operation_uuid);
        }
        $this->finalizeConfirmation($router, $revision, $owner, $user);
    }

    private function finalizeConfirmation(RouterNode $router, ConfigurationRevision $revision, string $owner, ?User $user = null): void
    {
        DB::transaction(function () use ($router, $revision, $owner, $user): void {
            $locked = RouterNode::query()->lockForUpdate()->findOrFail($router->id);
            if ($locked->pending_operation_uuid !== $owner || $locked->applied_revision_id !== $revision->id) {
                throw new RuntimeException('Confirmation ownership changed.');
            }
            $revision->forceFill(['confirmed_at' => now()])->save();
            $locked->forceFill([
                'pending_operation_uuid' => null, 'reconciliation_deadline_at' => null,
                'safe_mode_confirm_by' => null, 'safe_mode_status' => 'confirmed',
                'status' => $locked->desired_revision_id === $revision->id ? 'applied' : 'staged',
            ])->save();
            $this->auditLogWriter->write('router.safe_mode.confirmed', 'Confirmed the applied Safe Mode operation.', $locked, $user);
        }, attempts: 5);
    }

    public function rollbackExpiredSafeModes(?User $user = null): int
    {
        $count = 0;
        foreach (RouterNode::query()->where('safe_mode_status', 'pending_confirm')->where('safe_mode_confirm_by', '<=', now())->get() as $router) {
            try {
                $this->recoverConfirmedConfiguration($router, $user);
                $count++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $count;
    }

    /** Recovery is restricted to the snapshot recorded before this Safe Mode operation. */
    public function recoverConfirmedConfiguration(RouterNode $routerNode, ?User $user = null): void
    {
        [$router, $revision, $target, $owner] = DB::transaction(function () use ($routerNode): array {
            $router = RouterNode::query()->lockForUpdate()->findOrFail($routerNode->id);
            if ($router->pending_operation_uuid || $router->safe_mode_status !== 'pending_confirm') {
                throw new RuntimeException('No recoverable Safe Mode operation is available.');
            }
            $revision = $router->appliedRevision()->firstOrFail();
            $target = $router->revisions()->find(data_get($revision->diff_summary, 'previous_applied_revision_id'));
            $owner = (string) Str::uuid();
            $revision->forceFill(['diff_summary' => [...($revision->diff_summary ?? []), 'recovery_owner' => $owner]])->save();
            $router->forceFill(['pending_operation_uuid' => $owner, 'reconciliation_deadline_at' => now()->addMinutes(10)])->save();

            return [$router, $revision, $target, $owner];
        }, attempts: 5);

        try {
            if ($router->usesApplianceConnection()) {
                // Immutable dev.8 media owns its local snapshot and independent watchdog.
                // Never send an ordinary licensed apply as a recovery substitute.
                $observed = $this->applianceClient->observeConfiguration($router);
                if (data_get($observed, 'apply_operations.'.$revision->operation_uuid) !== 'rolled_back') {
                    throw new RuntimeException('Appliance recovery is pending; its independent watchdog must restore the snapshot.');
                }
            } else {
                $observed = $target
                    ? $this->agentClient->applyRoutingState($router->namespace_name, $target->applied_configuration ?? $target->desired_configuration)
                    : $this->agentClient->destroyNamespace($router->namespace_name);
            }
            $this->finalizeRecovery($router, $revision, $target, $owner, $observed, $user);
        } catch (Throwable $exception) {
            RouterNode::query()->whereKey($router->id)->where('pending_operation_uuid', $owner)->update([
                'pending_operation_uuid' => null, 'reconciliation_deadline_at' => null,
            ]);
            throw $exception;
        }
    }

    private function finalizeRecovery(RouterNode $router, ConfigurationRevision $revision, ?ConfigurationRevision $target, string $owner, array $observed, ?User $user = null): void
    {
        DB::transaction(function () use ($router, $revision, $target, $owner, $observed, $user): void {
            $locked = RouterNode::query()->lockForUpdate()->findOrFail($router->id);
            if ($locked->pending_operation_uuid !== $owner) {
                throw new RuntimeException('Recovery ownership changed.');
            }
            $configuration = $target?->applied_configuration ?? $target?->desired_configuration;
            if ($locked->usesApplianceConnection() && ! NeighborCapabilities::verifiesConfiguration($configuration['static_neighbors'] ?? [], $observed, $revision->desired_configuration['static_neighbors'] ?? [])) {
                throw new RuntimeException('The appliance has not verified restored ARP metadata and kernel pins. Recovery remains unresolved.');
            }
            $locked->forceFill([
                'applied_configuration' => $configuration,
                'applied_secret_configuration' => $target?->applied_secret_configuration ?? $target?->desired_secret_configuration,
                'applied_revision_id' => $target?->id,
                'configuration_lock_version' => $locked->configuration_lock_version + 1,
                'pending_operation_uuid' => null, 'reconciliation_deadline_at' => null,
                'safe_mode_confirm_by' => null, 'safe_mode_status' => $target ? 'confirmed' : 'rolled_back',
                'status' => $locked->desired_revision_id !== $target?->id ? 'staged' : 'applied',
            ])->save();
            $revision->forceFill(['apply_status' => 'rolled_back', 'failure_reason' => 'Safe Mode restored the recorded previous configuration.'])->save();
            $this->recordObservedState($locked, $target, $observed);
            $this->auditLogWriter->write('router.revision.rollback_requested', 'Recovered only the recorded previous Safe Mode snapshot.', $locked, $user);
        }, attempts: 5);
    }

    public function reconcileExpiredOperations(): int
    {
        $count = 0;
        foreach (RouterNode::query()->whereNotNull('pending_operation_uuid')->where('reconciliation_deadline_at', '<=', now())->get() as $router) {
            $owner = $router->pending_operation_uuid;
            $revision = $router->revisions()->where('operation_uuid', $owner)->first();
            if (! $revision) {
                // A terminated recovery worker may be retried only against the same snapshot.
                $recovery = $router->appliedRevision;
                if (data_get($recovery?->diff_summary, 'confirmation_owner') === $owner) {
                    try {
                        $observed = $router->usesApplianceConnection() ? $this->applianceClient->observeConfiguration($router) : [];
                        $state = $observed['apply_operations'][$recovery->operation_uuid] ?? null;
                        if (! $router->usesApplianceConnection() || $state === 'confirmed') {
                            $this->finalizeConfirmation($router, $recovery, $owner);
                            $count++;
                        } elseif ($state === 'rolled_back') {
                            $target = $router->revisions()->find(data_get($recovery->diff_summary, 'previous_applied_revision_id'));
                            $this->finalizeRecovery($router, $recovery, $target, $owner, $observed);
                            $count++;
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                    }

                    continue;
                }
                if (data_get($recovery?->diff_summary, 'recovery_owner') === $owner) {
                    RouterNode::query()->whereKey($router->id)->where('pending_operation_uuid', $owner)->update(['pending_operation_uuid' => null]);
                    try {
                        $this->recoverConfirmedConfiguration($router->fresh());
                        $count++;
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }

                continue;
            }
            try {
                $observed = $router->usesApplianceConnection()
                    ? $this->applianceClient->observeConfiguration($router)
                    : $this->agentClient->observeRoutingState($router->namespace_name);
                $state = $observed['apply_operations'][$owner] ?? null;
                if (in_array($state, ['applied', 'confirmed'], true)) {
                    $minutes = $state === 'confirmed' || $revision->confirm_by === null ? null : 1;
                    $this->finalizeSuccessfulApply($router, $revision, $owner, $observed, $minutes, null);
                    $count++;
                } elseif (in_array($state, ['rolled_back', 'failed'], true)) {
                    $target = $router->revisions()->find(data_get($revision->diff_summary, 'previous_applied_revision_id'));
                    $this->finalizeRecovery($router, $revision, $target, $owner, $observed);
                    $count++;
                } else {
                    // Absence is not proof of rejection: reboot/pruned status can hide success.
                    RouterNode::query()->whereKey($router->id)->where('pending_operation_uuid', $owner)->update(['status' => 'outcome-unknown', 'reconciliation_deadline_at' => now()->addMinute()]);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $count;
    }

    public function refreshObservedState(RouterNode $routerNode, ?User $user = null): ObservedState
    {
        $refreshedRouterNode = $routerNode->fresh(['appliedRevision', 'desiredRevision']) ?? $routerNode;
        $observedState = $refreshedRouterNode->usesApplianceConnection()
            ? $this->applianceClient->observeConfiguration($refreshedRouterNode)
            : $this->agentClient->observeRoutingState($refreshedRouterNode->namespace_name);

        $recordedState = $this->recordObservedState(
            $refreshedRouterNode,
            $refreshedRouterNode->appliedRevision ?? $refreshedRouterNode->desiredRevision,
            $observedState,
        );

        $this->auditLogWriter->write(
            'router.state.refreshed',
            "Refreshed observed routing state for {$refreshedRouterNode->namespace_name}.",
            $refreshedRouterNode,
            $user,
            metadata: [
                'configuration_revision_id' => $recordedState->configuration_revision_id,
                'observed_state_id' => $recordedState->id,
            ],
        );

        return $recordedState;
    }

    public function rollbackToRevision(RouterNode $routerNode, ConfigurationRevision $targetRevision, ?User $user = null): ObservedState
    {
        $revision = DB::transaction(function () use ($routerNode, $targetRevision, $user): ConfigurationRevision {
            $routerNode = RouterNode::query()->lockForUpdate()->findOrFail($routerNode->id);
            if ($routerNode->pending_operation_uuid || $routerNode->safe_mode_status === 'pending_confirm') {
                throw new RuntimeException('Finish the pending operation before requesting rollback.');
            }
            $this->licenseEnforcement->assertMutationAllowed($routerNode);
            $routerNode->forceFill([
                'desired_configuration' => $targetRevision->desired_configuration,
                'desired_secret_configuration' => $targetRevision->desired_secret_configuration,
                'management_interface' => data_get($targetRevision->desired_configuration, 'management.interface', $routerNode->management_interface),
                'management_cidr' => data_get($targetRevision->desired_configuration, 'management.cidr', $routerNode->management_cidr),
            ])->save();

            return $this->createRevision($routerNode, $user, $targetRevision->id);

        }, attempts: 5);

        $this->auditLogWriter->write(
            'router.revision.rollback_requested',
            "Rollback to revision {$targetRevision->revision_number} requested for {$routerNode->namespace_name}.",
            $routerNode,
            $user,
            metadata: ['target_revision_id' => $targetRevision->id, 'new_revision_id' => $revision->id],
        );

        return $this->applyCurrentRevision($routerNode, $user, requestedRevisionId: $revision->id);
    }

    /**
     * @param  array<string, mixed>  $agentState
     */
    public function recordObservedState(
        RouterNode $routerNode,
        ?ConfigurationRevision $revision,
        array $agentState,
    ): ObservedState {
        return $routerNode->observedStates()->create([
            'configuration_revision_id' => $revision?->id,
            'source' => $routerNode->usesApplianceConnection() ? 'appliance' : 'agent',
            'summary' => [
                'applied_revision_id' => $routerNode->applied_revision_id,
                'desired_revision_id' => $routerNode->desired_revision_id,
                'namespace' => $routerNode->namespace_name,
                'interface' => $routerNode->management_interface,
                'management_cidr' => $routerNode->management_cidr,
                'status' => $routerNode->status,
            ],
            'state' => $agentState,
            'recorded_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    public function desiredConfigurationFor(RouterNode $routerNode): array
    {
        return [
            ...($routerNode->desired_configuration ?? []),
            'namespace' => $routerNode->namespace_name,
            'management' => [
                'interface' => $routerNode->management_interface,
                'cidr' => $routerNode->management_cidr,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function desiredSecretConfigurationFor(RouterNode $routerNode): array
    {
        return $routerNode->desired_secret_configuration ?? [];
    }

    private function configurationAlreadyApplied(RouterNode $routerNode, ConfigurationRevision $revision): bool
    {
        return $routerNode->applied_configuration === $revision->desired_configuration
            && $routerNode->applied_secret_configuration === $revision->desired_secret_configuration;
    }

    /**
     * @param  array<string, mixed>  $agentState
     */
    private function finalizeSuccessfulApply(
        RouterNode $routerNode,
        ConfigurationRevision $revision,
        string $operationUuid,
        array $agentState,
        ?int $safeModeMinutes,
        ?User $user,
        bool $configurationApplied = true,
    ): ObservedState {
        [$routerNode, $revision] = DB::transaction(function () use (
            $operationUuid,
            $revision,
            $routerNode,
            $safeModeMinutes,
            $user,
            $configurationApplied,
            $agentState,
        ): array {
            /** @var RouterNode $lockedRouterNode */
            $lockedRouterNode = RouterNode::query()
                ->lockForUpdate()
                ->findOrFail($routerNode->getKey());

            /** @var ConfigurationRevision $lockedRevision */
            $lockedRevision = $lockedRouterNode->revisions()
                ->whereKey($revision->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRouterNode->pending_operation_uuid !== $operationUuid || $lockedRevision->operation_uuid !== $operationUuid) {
                throw new RuntimeException('Pending operation does not match the apply request that completed.');
            }

            $armedSafeModeMinutes = $configurationApplied ? $safeModeMinutes : null;
            $confirmBy = $armedSafeModeMinutes ? $lockedRevision->confirm_by : null;
            $diffSummary = $lockedRevision->diff_summary ?? [];

            $proof = NeighborCapabilities::proof($lockedRouterNode, $lockedRouterNode->applied_configuration['static_neighbors'] ?? [], $lockedRevision->desired_configuration['static_neighbors'] ?? [], $agentState);
            if ($configurationApplied && $proof !== null) {
                $diffSummary['neighbor_proof'] = $proof;
            }

            $diffSummary['previous_applied_configuration'] = $lockedRouterNode->applied_configuration;
            $diffSummary['previous_applied_secret_configuration_present'] = ! empty($lockedRouterNode->applied_secret_configuration);
            $diffSummary['previous_applied_revision_id'] = $lockedRouterNode->applied_revision_id;

            $lockedRevision->forceFill([
                'apply_status' => 'applied',
                'applied_at' => now(),
                'applied_configuration' => $lockedRevision->desired_configuration,
                'applied_secret_configuration' => $lockedRevision->desired_secret_configuration,
                'confirm_by' => $confirmBy,
                'confirmed_at' => $confirmBy === null ? now() : null,
                'diff_summary' => $diffSummary,
            ])->save();

            $lockedRouterNode->forceFill([
                'applied_configuration' => $lockedRevision->desired_configuration,
                'applied_secret_configuration' => $lockedRevision->desired_secret_configuration,
                'applied_revision_id' => $lockedRevision->id,
                'configuration_lock_version' => $lockedRouterNode->configuration_lock_version + 1,
                'pending_operation_uuid' => null,
                'reconciliation_deadline_at' => null,
                'safe_mode_confirm_by' => $confirmBy,
                'safe_mode_status' => $confirmBy ? 'pending_confirm' : 'confirmed',
                'status' => $lockedRouterNode->desired_revision_id !== $lockedRevision->id ? 'staged' : ($confirmBy ? 'safe-mode-pending' : 'applied'),
            ])->save();

            if ($lockedRouterNode->desired_revision_id !== $lockedRevision->id) {
                $lockedRouterNode->desiredRevision()->where('base_lock_version', $lockedRevision->base_lock_version)->update(['base_lock_version' => $lockedRouterNode->configuration_lock_version]);
            }

            $this->auditLogWriter->write(
                'router.revision.applied',
                "Applied revision {$lockedRevision->revision_number} to {$lockedRouterNode->namespace_name}.",
                $lockedRouterNode,
                $user,
                metadata: [
                    'revision_id' => $lockedRevision->id,
                    'safe_mode_minutes' => $armedSafeModeMinutes,
                ],
            );

            return [$lockedRouterNode->fresh(), $lockedRevision->fresh()];
        }, attempts: 5);

        return $this->recordObservedState($routerNode, $revision, $agentState);
    }

    private function finalizeFailedApply(
        RouterNode $routerNode,
        ConfigurationRevision $revision,
        string $operationUuid,
        Throwable $throwable,
        ?User $user,
    ): void {
        DB::transaction(function () use ($operationUuid, $revision, $routerNode, $throwable, $user): void {
            /** @var RouterNode $lockedRouterNode */
            $lockedRouterNode = RouterNode::query()
                ->lockForUpdate()
                ->findOrFail($routerNode->getKey());

            /** @var ConfigurationRevision $lockedRevision */
            $lockedRevision = $lockedRouterNode->revisions()
                ->whereKey($revision->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRouterNode->pending_operation_uuid !== $operationUuid || $lockedRevision->operation_uuid !== $operationUuid) {
                return;
            }

            $unknown = ! $throwable instanceof ApplianceTransportException || in_array($throwable->kind, [ApplianceTransportException::KIND_TIMEOUT, ApplianceTransportException::KIND_UNREACHABLE, ApplianceTransportException::KIND_MALFORMED_RESPONSE], true);
            $lockedRevision->forceFill([
                'apply_status' => $unknown ? 'outcome-unknown' : 'failed',
                'failure_reason' => $throwable->getMessage(),
            ])->save();

            $lockedRouterNode->forceFill([
                'pending_operation_uuid' => $unknown ? $operationUuid : null,
                'reconciliation_deadline_at' => $unknown ? now()->addMinute() : null,
                'status' => 'error',
            ])->save();

            $this->auditLogWriter->write(
                'router.revision.apply_failed',
                "Failed to apply revision {$lockedRevision->revision_number} to {$lockedRouterNode->namespace_name}.",
                $lockedRouterNode,
                $user,
                'failed',
                ['revision_id' => $lockedRevision->id, 'message' => $throwable->getMessage()],
            );
        }, attempts: 5);
    }
}
