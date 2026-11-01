<?php

namespace App\Services;

use App\Models\ConfigurationRevision;
use App\Models\ObservedState;
use App\Models\RouterNode;
use App\Models\User;
use App\Services\Licensing\EntitlementGate;
use App\Services\Licensing\LicenseEnforcement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        });

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
        $this->entitlementGate->assertDesiredStateAllowed($routerNode, $desiredState, $secretState);

        $routerNode->forceFill([
            'desired_configuration' => [
                ...($routerNode->desired_configuration ?? []),
                'interfaces' => array_values($desiredState['interfaces'] ?? []),
                'addresses' => array_values($desiredState['addresses'] ?? []),
                'routes' => array_values($desiredState['routes'] ?? []),
                'bridge_vlans' => array_values($desiredState['bridge_vlans'] ?? []),
                'services' => $desiredState['services'] ?? [],
                'forwarding' => $desiredState['forwarding'] ?? [],
                'firewall' => $desiredState['firewall'] ?? [],
                'license_entitlements' => $this->entitlementGate->applianceEntitlementSnapshot($routerNode),
            ],
            'desired_secret_configuration' => [
                ...($routerNode->desired_secret_configuration ?? []),
                'services' => [
                    'pppoe_clients' => data_get($secretState, 'services.pppoe_clients', []),
                ],
            ],
        ])->save();

        $revision = $this->createRevision($routerNode, $user);

        $this->auditLogWriter->write(
            'router.desired_state.updated',
            "Updated desired routing state for {$routerNode->namespace_name}.",
            $routerNode,
            $user,
            metadata: ['revision_id' => $revision->id],
        );

        return $revision;
    }

    public function applyCurrentRevision(
        RouterNode $routerNode,
        ?User $user = null,
        ?int $expectedLockVersion = null,
        ?int $safeModeMinutes = null,
    ): ObservedState {
        $this->licenseEnforcement->assertMutationAllowed($routerNode);

        [$routerNode, $revision, $operationUuid, $safeModeMinutes] = DB::transaction(
            function () use ($expectedLockVersion, $routerNode, $safeModeMinutes): array {
                /** @var RouterNode $lockedRouterNode */
                $lockedRouterNode = RouterNode::query()
                    ->lockForUpdate()
                    ->findOrFail($routerNode->getKey());

                $desiredRevisionId = $lockedRouterNode->desired_revision_id ?? $lockedRouterNode->current_revision_id;

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

                $operationUuid = (string) Str::uuid();

                $revision->forceFill([
                    'apply_status' => 'applying',
                    'operation_uuid' => $operationUuid,
                    'confirm_by' => $safeModeMinutes ? now()->addMinutes($safeModeMinutes) : null,
                    'confirmed_at' => null,
                    'failure_reason' => null,
                    'stale_at' => null,
                ])->save();

                $lockedRouterNode->forceFill([
                    'pending_operation_uuid' => $operationUuid,
                    'reconciliation_deadline_at' => now()->addSeconds(30),
                    'status' => 'applying',
                ])->save();

                return [$lockedRouterNode, $revision, $operationUuid, $safeModeMinutes];
            },
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
        DB::transaction(function () use ($routerNode, $user): void {
            /** @var RouterNode $lockedRouterNode */
            $lockedRouterNode = RouterNode::query()
                ->lockForUpdate()
                ->findOrFail($routerNode->getKey());

            if ($lockedRouterNode->safe_mode_status !== 'pending_confirm' || $lockedRouterNode->applied_revision_id === null) {
                throw new RuntimeException('There is no pending Safe Mode session to confirm.');
            }

            /** @var ConfigurationRevision $revision */
            $revision = $lockedRouterNode->revisions()
                ->whereKey($lockedRouterNode->applied_revision_id)
                ->lockForUpdate()
                ->firstOrFail();

            $revision->forceFill([
                'confirmed_at' => now(),
            ])->save();

            if ($lockedRouterNode->usesApplianceConnection() && $revision->operation_uuid !== null) {
                $this->applianceClient->confirmSafeMode($lockedRouterNode, $revision->operation_uuid);
            }

            $lockedRouterNode->forceFill([
                'safe_mode_confirm_by' => null,
                'safe_mode_status' => 'confirmed',
                'status' => 'applied',
            ])->save();

            $this->auditLogWriter->write(
                'router.safe_mode.confirmed',
                "Safe Mode was confirmed for {$lockedRouterNode->namespace_name}.",
                $lockedRouterNode,
                $user,
                metadata: ['revision_id' => $revision->id],
            );
        });
    }

    public function rollbackExpiredSafeModes(?User $user = null): int
    {
        $routerNodeIds = RouterNode::query()
            ->where('safe_mode_status', 'pending_confirm')
            ->whereNotNull('safe_mode_confirm_by')
            ->where('safe_mode_confirm_by', '<=', now())
            ->orderBy('id')
            ->pluck('id');

        $rolledBackCount = 0;

        foreach ($routerNodeIds as $routerNodeId) {
            /** @var RouterNode $routerNode */
            $routerNode = RouterNode::query()->findOrFail($routerNodeId);
            $currentRevision = $routerNode->appliedRevision;

            if ($currentRevision === null) {
                continue;
            }

            $currentRevision->forceFill([
                'apply_status' => 'stale',
                'stale_at' => now(),
                'failure_reason' => 'Safe Mode confirmation expired.',
            ])->save();

            $previousAppliedRevisionId = data_get($currentRevision->diff_summary, 'previous_applied_revision_id');

            if (is_int($previousAppliedRevisionId) || ctype_digit((string) $previousAppliedRevisionId)) {
                /** @var ConfigurationRevision|null $targetRevision */
                $targetRevision = $routerNode->revisions()->find($previousAppliedRevisionId);

                if ($targetRevision !== null) {
                    $this->rollbackToRevision($routerNode, $targetRevision, $user);
                    $rolledBackCount++;

                    continue;
                }
            }

            $response = $routerNode->usesApplianceConnection()
                ? $this->applianceClient->observeConfiguration($routerNode)
                : $this->agentClient->destroyNamespace($routerNode->namespace_name);

            DB::transaction(function () use ($currentRevision, $routerNode): void {
                /** @var RouterNode $lockedRouterNode */
                $lockedRouterNode = RouterNode::query()
                    ->lockForUpdate()
                    ->findOrFail($routerNode->getKey());

                $lockedRouterNode->forceFill([
                    'applied_configuration' => null,
                    'applied_secret_configuration' => null,
                    'applied_revision_id' => null,
                    'configuration_lock_version' => $lockedRouterNode->configuration_lock_version + 1,
                    'pending_operation_uuid' => null,
                    'reconciliation_deadline_at' => null,
                    'safe_mode_confirm_by' => null,
                    'safe_mode_status' => 'rolled_back',
                    'status' => 'rolled-back',
                ])->save();

                $currentRevision->forceFill([
                    'confirmed_at' => null,
                ])->save();
            });

            $this->recordObservedState($routerNode->fresh(), null, $response);
            $this->auditLogWriter->write(
                'router.safe_mode.auto_rolled_back',
                "Safe Mode expired and {$routerNode->namespace_name} was reverted to an empty applied state.",
                $routerNode->fresh(),
                $user,
                metadata: ['expired_revision_id' => $currentRevision->id],
            );

            $rolledBackCount++;
        }

        return $rolledBackCount;
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
        $routerNode->forceFill([
            'desired_configuration' => $targetRevision->desired_configuration,
            'desired_secret_configuration' => $targetRevision->desired_secret_configuration,
            'management_interface' => data_get($targetRevision->desired_configuration, 'management.interface', $routerNode->management_interface),
            'management_cidr' => data_get($targetRevision->desired_configuration, 'management.cidr', $routerNode->management_cidr),
        ])->save();

        $revision = $this->createRevision($routerNode, $user, $targetRevision->id);

        $this->auditLogWriter->write(
            'router.revision.rollback_requested',
            "Rollback to revision {$targetRevision->revision_number} requested for {$routerNode->namespace_name}.",
            $routerNode,
            $user,
            metadata: ['target_revision_id' => $targetRevision->id, 'new_revision_id' => $revision->id],
        );

        return $this->applyCurrentRevision($routerNode, $user);
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

            if ($lockedRouterNode->pending_operation_uuid !== $operationUuid) {
                throw new RuntimeException('Pending operation does not match the apply request that completed.');
            }

            $armedSafeModeMinutes = $configurationApplied ? $safeModeMinutes : null;
            $confirmBy = $armedSafeModeMinutes ? now()->addMinutes($armedSafeModeMinutes) : null;
            $diffSummary = $lockedRevision->diff_summary ?? [];

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
                'current_revision_id' => $lockedRevision->id,
                'desired_configuration' => $lockedRevision->desired_configuration,
                'desired_secret_configuration' => $lockedRevision->desired_secret_configuration,
                'desired_revision_id' => $lockedRevision->id,
                'pending_operation_uuid' => null,
                'reconciliation_deadline_at' => null,
                'safe_mode_confirm_by' => $confirmBy,
                'safe_mode_status' => $confirmBy ? 'pending_confirm' : 'confirmed',
                'status' => $confirmBy ? 'safe-mode-pending' : 'applied',
            ])->save();

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
        });

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

            if ($lockedRouterNode->pending_operation_uuid !== $operationUuid) {
                return;
            }

            $lockedRevision->forceFill([
                'apply_status' => 'failed',
                'failure_reason' => $throwable->getMessage(),
            ])->save();

            $lockedRouterNode->forceFill([
                'pending_operation_uuid' => null,
                'reconciliation_deadline_at' => null,
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
        });
    }
}
