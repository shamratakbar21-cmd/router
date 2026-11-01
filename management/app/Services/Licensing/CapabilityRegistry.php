<?php

namespace App\Services\Licensing;

use App\Models\LicenseActivation;
use App\Models\RouterNode;

/**
 * Resolves supported entitlements for a router and answers feature/limit questions.
 */
class CapabilityRegistry
{
    /**
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        return config('capabilities', []);
    }

    /**
     * @return list<string>
     */
    public function supportedFeatureCodes(): array
    {
        $codes = [];
        foreach (config('capabilities.features', []) as $code => $meta) {
            if (! empty($meta['supported'])) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Entitlements from the active activation, or conservative defaults when unlicensed.
     *
     * @return array{features: list<string>, max_pppoe_sessions: int, max_routers: int, source: string}
     */
    public function entitlementsFor(RouterNode $routerNode): array
    {
        $defaults = [
            'features' => $this->supportedFeatureCodes(),
            'max_pppoe_sessions' => 0,
            'max_routers' => 0,
            'source' => 'unlicensed_bringup',
        ];

        if ($routerNode->active_license_activation_id === null) {
            return $defaults;
        }

        $activation = LicenseActivation::query()
            ->with('license')
            ->find($routerNode->active_license_activation_id);

        if ($activation === null || ! $activation->isActive()) {
            return $defaults;
        }

        $raw = $activation->license?->entitlements ?? [];
        $features = array_values(array_filter(
            (array) ($raw['features'] ?? []),
            fn ($code) => is_string($code) && in_array($code, $this->supportedFeatureCodes(), true),
        ));

        return [
            'features' => $features,
            'max_pppoe_sessions' => (int) ($raw['max_pppoe_sessions'] ?? 0),
            'max_routers' => (int) ($raw['max_routers'] ?? 0),
            'source' => 'license',
        ];
    }

    public function hasFeature(RouterNode $routerNode, string $feature): bool
    {
        if (! in_array($feature, $this->supportedFeatureCodes(), true)) {
            return false;
        }

        $entitlements = $this->entitlementsFor($routerNode);

        // Unlicensed bring-up keeps core networking available.
        if ($entitlements['source'] === 'unlicensed_bringup') {
            return in_array($feature, ['routing', 'firewall', 'dhcp', 'dns', 'pppoe'], true);
        }

        return in_array($feature, $entitlements['features'], true);
    }

    public function maxPppoeSessions(RouterNode $routerNode): ?int
    {
        $limit = $this->entitlementsFor($routerNode)['max_pppoe_sessions'];

        return $limit === 0 ? null : $limit;
    }
}
