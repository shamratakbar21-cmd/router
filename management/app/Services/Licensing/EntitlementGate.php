<?php

namespace App\Services\Licensing;

use App\Models\RouterNode;
use RuntimeException;

/**
 * Server-side entitlement checks for desired-state mutations.
 */
class EntitlementGate
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly LicenseEnforcement $licenseEnforcement,
    ) {}

    /**
     * @param  array<string, mixed>  $desiredState
     * @param  array<string, mixed>  $secretState
     */
    public function assertDesiredStateAllowed(RouterNode $routerNode, array $desiredState, array $secretState = []): void
    {
        $this->licenseEnforcement->assertMutationAllowed($routerNode);

        $services = (array) ($desiredState['services'] ?? []);
        $pppoeClients = array_values(array_filter(
            (array) ($services['pppoe_clients'] ?? []),
            fn ($row) => is_array($row) && trim((string) ($row['name'] ?? '')) !== '',
        ));

        if ($pppoeClients !== [] && ! $this->capabilities->hasFeature($routerNode, 'pppoe')) {
            throw new RuntimeException('Plan entitlement "pppoe" is required to configure PPPoE clients.');
        }

        $maxSessions = $this->capabilities->maxPppoeSessions($routerNode);
        if ($maxSessions !== null && count($pppoeClients) > $maxSessions) {
            throw new RuntimeException(
                "PPPoE session admission limit is {$maxSessions}; desired state has ".count($pppoeClients)
                .' profiles. Established sessions are not disconnected by this check; reduce profiles or upgrade the plan.',
            );
        }

        $hasShapingRates = collect($pppoeClients)->contains(function ($client): bool {
            return (int) ($client['download_kbps'] ?? 0) > 0 || (int) ($client['upload_kbps'] ?? 0) > 0;
        });

        if ($hasShapingRates && ! $this->capabilities->hasFeature($routerNode, 'shaping')) {
            throw new RuntimeException('Plan entitlement "shaping" is required to set PPPoE rate limits.');
        }

        if (! empty($desiredState['firewall']) && ! $this->capabilities->hasFeature($routerNode, 'firewall')) {
            throw new RuntimeException('Plan entitlement "firewall" is required to configure firewall/NAT.');
        }

        if (! empty($services['dhcp_servers']) && ! $this->capabilities->hasFeature($routerNode, 'dhcp')) {
            throw new RuntimeException('Plan entitlement "dhcp" is required to configure DHCP servers.');
        }

        $dns = (array) ($services['dns'] ?? []);
        if ((! empty($dns['forwarders']) || ! empty($dns['search_domains']))
            && ! $this->capabilities->hasFeature($routerNode, 'dns')) {
            throw new RuntimeException('Plan entitlement "dns" is required to configure DNS.');
        }
    }

    /**
     * Write appliance-side entitlement snapshot for offline admission enforcement.
     *
     * @return array<string, mixed>
     */
    public function applianceEntitlementSnapshot(RouterNode $routerNode): array
    {
        $entitlements = $this->capabilities->entitlementsFor($routerNode);
        $status = $this->licenseEnforcement->evaluateRouter($routerNode);

        return [
            'schema_version' => 1,
            'state' => $status['state'],
            'allow_entitlement_mutations' => $status['allowed'],
            'features' => $entitlements['features'],
            'max_pppoe_sessions' => $entitlements['max_pppoe_sessions'],
            'session_limit_governs' => 'admission_of_new_sessions',
            'preserve_established_on_restrict' => true,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
