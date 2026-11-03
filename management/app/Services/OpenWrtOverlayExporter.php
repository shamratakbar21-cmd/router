<?php

namespace App\Services;

use App\Models\RouterNode;
use Illuminate\Support\Facades\File;
use RuntimeException;

class OpenWrtOverlayExporter
{
    public function __construct(private readonly OpenWrtRuntimeConfigRenderer $runtimeConfigRenderer) {}

    /**
     * @return array<string, string>
     */
    public function export(
        RouterNode $routerNode,
        string $targetDirectory,
        string $source = 'applied',
        bool $includeSecrets = false,
    ): array {
        $configuration = match ($source) {
            'applied' => $routerNode->applied_configuration ?? [],
            'desired' => $routerNode->desired_configuration ?? [],
            default => throw new RuntimeException("Unsupported export source [{$source}]."),
        };

        $secretConfiguration = match ($source) {
            'applied' => $routerNode->applied_secret_configuration ?? [],
            'desired' => $routerNode->desired_secret_configuration ?? [],
            default => [],
        };

        if ($configuration === []) {
            throw new RuntimeException("Router {$routerNode->namespace_name} has no {$source} configuration to export.");
        }

        $renderedConfig = $this->runtimeConfigRenderer->render(
            $configuration,
            $secretConfiguration,
            ! $includeSecrets,
        );
        $resolvedTargetDirectory = rtrim($targetDirectory, '/');
        $configDirectory = "{$resolvedTargetDirectory}/etc/config";
        $metadataDirectory = "{$resolvedTargetDirectory}/etc/nurrouter";
        $networkPath = "{$configDirectory}/network";
        $dhcpPath = "{$configDirectory}/dhcp";
        $firewallPath = "{$configDirectory}/firewall";
        $metadataPath = "{$metadataDirectory}/overlay-manifest.json";

        File::ensureDirectoryExists($resolvedTargetDirectory, 0700);
        chmod($resolvedTargetDirectory, 0700);
        File::ensureDirectoryExists($configDirectory, 0700);
        File::ensureDirectoryExists($metadataDirectory, 0700);
        chmod($resolvedTargetDirectory, 0700);
        chmod($configDirectory, 0700);
        chmod($metadataDirectory, 0700);

        $shapingPath = "{$metadataDirectory}/pppoe-shaping.json";
        $entitlementsPath = "{$metadataDirectory}/license-entitlements.json";

        File::put("{$metadataDirectory}/neighbors.json", json_encode(array_map(
            fn (array $entry): array => [
                'ip' => $entry['ip'], 'mac' => strtolower($entry['mac']),
                'interface' => $entry['interface'], 'disabled' => (bool) ($entry['disabled'] ?? false),
                'comment' => (string) ($entry['comment'] ?? ''),
            ], $configuration['static_neighbors'] ?? []), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        chmod("{$metadataDirectory}/neighbors.json", 0600);

        File::put($networkPath, $renderedConfig['network']);
        File::put($dhcpPath, $renderedConfig['dhcp']);
        File::put($firewallPath, $renderedConfig['firewall']);
        File::put($shapingPath, $renderedConfig['pppoe_shaping']);
        File::put(
            $entitlementsPath,
            json_encode(
                $configuration['license_entitlements'] ?? [
                    'schema_version' => 1,
                    'state' => 'unlicensed',
                    'allow_entitlement_mutations' => true,
                    'features' => ['routing', 'firewall', 'dhcp', 'dns', 'pppoe'],
                    'max_pppoe_sessions' => 0,
                    'session_limit_governs' => 'admission_of_new_sessions',
                    'preserve_established_on_restrict' => true,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            )."\n",
        );
        $bras = data_get($configuration, 'services.pppoe_server', ['enabled' => false]);
        foreach ($bras['subscribers'] ?? [] as $index => $subscriber) {
            $bras['subscribers'][$index]['password'] = ! $includeSecrets ? '[REDACTED]' : (data_get($secretConfiguration, 'services.pppoe_server.subscribers', [])[$subscriber['username']] ?? '');
        }
        if (($bras['auth_mode'] ?? '') === 'radius') {
            $bras['radius']['secret'] = ! $includeSecrets ? '[REDACTED]' : data_get($secretConfiguration, 'services.pppoe_server.radius_secret', '');
        }
        File::put("{$metadataDirectory}/bras.json", json_encode($bras, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        chmod("{$metadataDirectory}/bras.json", 0600);
        File::put($metadataPath, json_encode([
            'router' => [
                'id' => $routerNode->id,
                'name' => $routerNode->name,
                'namespace' => $routerNode->namespace_name,
                'management_interface' => $routerNode->management_interface,
                'management_cidr' => $routerNode->management_cidr,
            ],
            'source' => $source,
            'exported_at' => now()->toIso8601String(),
            'files' => [
                'network' => $networkPath,
                'dhcp' => $dhcpPath,
                'firewall' => $firewallPath,
                'pppoe_shaping' => $shapingPath,
                'license_entitlements' => $entitlementsPath,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        foreach ([$networkPath, $dhcpPath, $firewallPath, $shapingPath, $entitlementsPath, $metadataPath] as $path) {
            chmod($path, 0600);
        }

        return [
            'network' => $networkPath,
            'dhcp' => $dhcpPath,
            'firewall' => $firewallPath,
            'pppoe_shaping' => $shapingPath,
            'license_entitlements' => $entitlementsPath,
            'manifest' => $metadataPath,
        ];
    }
}
