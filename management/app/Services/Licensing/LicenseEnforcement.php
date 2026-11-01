<?php

namespace App\Services\Licensing;

use App\Models\LicenseActivation;
use App\Models\RouterNode;
use RuntimeException;

/**
 * Safe entitlement enforcement for WinControl mutations.
 *
 * Never disconnects subscribers, removes firewall rules, or blocks local recovery/Safe Mode.
 */
class LicenseEnforcement
{
    public function __construct(private readonly LicenseService $licenseService) {}

    /**
     * @return array{allowed: bool, state: string, message: string, status: array<string, mixed>}
     */
    public function evaluateRouter(RouterNode $routerNode, bool $serverReachable = true): array
    {
        if (! config('licensing.safe_enforcement.block_wincontrol_mutations_when_restricted', true)) {
            return [
                'allowed' => true,
                'state' => 'enforcement_disabled',
                'message' => 'License mutation enforcement is disabled.',
                'status' => [],
            ];
        }

        if ($routerNode->active_license_activation_id === null) {
            $status = LicensePolicyMatrix::statusPayload(LicensePolicyMatrix::STATE_UNLICENSED);

            return [
                'allowed' => (bool) $status['allow_entitlement_mutations'],
                'state' => $status['state'],
                'message' => $status['message'],
                'status' => $status,
            ];
        }

        $activation = LicenseActivation::query()
            ->with('license')
            ->find($routerNode->active_license_activation_id);

        if ($activation === null) {
            $status = LicensePolicyMatrix::statusPayload(LicensePolicyMatrix::STATE_UNLICENSED);

            return [
                'allowed' => true,
                'state' => $status['state'],
                'message' => 'License activation record missing; treating as unlicensed for recovery.',
                'status' => $status,
            ];
        }

        $status = $this->licenseService->evaluateStatus($activation, serverReachable: $serverReachable);

        return [
            'allowed' => (bool) $status['allow_entitlement_mutations'],
            'state' => (string) $status['state'],
            'message' => (string) $status['message'],
            'status' => $status,
        ];
    }

    public function assertMutationAllowed(RouterNode $routerNode): void
    {
        $result = $this->evaluateRouter($routerNode);

        $routerNode->forceFill([
            'license_status' => $result['state'],
            'license_checked_at' => now(),
        ])->save();

        if ($result['allowed']) {
            return;
        }

        throw new RuntimeException(
            'License blocks this WinControl mutation ('.$result['state'].'): '.$result['message']
            .' Existing subscriber sessions, firewall rules, and local recovery are not disrupted.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function serverOutagePolicy(): array
    {
        $permissions = LicensePolicyMatrix::permissions(LicensePolicyMatrix::STATE_OFFLINE_WITHIN_GRACE);

        return [
            'state' => LicensePolicyMatrix::STATE_OFFLINE_WITHIN_GRACE,
            'disconnect_subscribers' => $permissions['disconnect_subscribers'],
            'remove_firewall_rules' => $permissions['remove_firewall_rules'],
            'block_local_recovery' => $permissions['block_local_recovery'],
            'block_safe_mode' => $permissions['block_safe_mode'],
            'allow_entitlement_mutations' => $permissions['allow_entitlement_mutations'],
            'use_cached_license' => true,
            'max_offline_revocation_delay_days' => (int) config('licensing.max_offline_revocation_delay_days', 14),
            'message' => $permissions['message'],
        ];
    }
}
