<?php

namespace App\Services;

use App\Models\RouterNode;
use App\Models\User;
use App\Services\Licensing\EntitlementGate;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Versioned router configuration backup / restore for WinControl.
 *
 * Device identity and license ownership are NEVER included. Migration to new
 * hardware requires a separate license device transfer.
 */
class ConfigurationBackupService
{
    public const FORMAT_VERSION = 1;

    public function __construct(
        private readonly AuditLogWriter $auditLogWriter,
        private readonly EntitlementGate $entitlementGate,
    ) {}

    /**
     * @return array{document: array<string, mixed>, json: string, integrity: string}
     */
    public function export(RouterNode $routerNode, ?User $actor = null, bool $includeSecrets = false): array
    {
        $desired = $routerNode->desired_configuration ?? [];
        unset($desired['license_entitlements']);

        $secrets = [];
        if ($includeSecrets) {
            $secrets = $routerNode->desired_secret_configuration ?? [];
        }

        $document = [
            'format' => 'nurrouter-config-backup',
            'format_version' => self::FORMAT_VERSION,
            'product_version' => trim((string) @file_get_contents(base_path('../VERSION'))) ?: 'unknown',
            'exported_at' => now()->toIso8601String(),
            'source' => [
                'router_name' => $routerNode->name,
                'namespace_name' => $routerNode->namespace_name,
                // Explicitly omit device_id and license fields.
            ],
            'compatibility' => [
                'min_format_version' => 1,
                'max_format_version' => self::FORMAT_VERSION,
            ],
            'desired_configuration' => $desired,
            'secrets_included' => $includeSecrets,
            'desired_secret_configuration' => $includeSecrets
                ? ['ciphertext' => Crypt::encryptString(json_encode($secrets, JSON_UNESCAPED_SLASHES) ?: '{}')]
                : null,
            'mode_hints' => [
                'same_router' => 'Restore onto the same WinControl router record; device_id and license remain unchanged.',
                'replacement_hardware' => 'Enroll the new appliance, run license device transfer, then restore config without copying device_id.',
                'clone_new_device' => 'Disk clones copy device-id/license files; treat as unsupported ownership transfer — use official transfer.',
            ],
        ];

        $bodyJson = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($bodyJson === false) {
            throw new RuntimeException('Unable to encode configuration backup.');
        }

        $integrity = hash('sha256', $bodyJson);
        $document['integrity'] = [
            'alg' => 'sha256',
            'value' => $integrity,
            'covers' => 'sha256 of JSON object without the integrity field (JSON_UNESCAPED_SLASHES|UNICODE, key order as exported)',
        ];

        $exportJson = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($exportJson === false) {
            throw new RuntimeException('Unable to encode configuration backup with integrity.');
        }

        $this->auditLogWriter->write(
            'router.backup.exported',
            "Exported configuration backup for {$routerNode->namespace_name}.",
            $routerNode,
            $actor,
            metadata: [
                'format_version' => self::FORMAT_VERSION,
                'secrets_included' => $includeSecrets,
                'integrity' => $integrity,
            ],
        );

        return [
            'document' => $document,
            'json' => $exportJson,
            'integrity' => $integrity,
        ];
    }

    /**
     * @return array{ok: bool, errors: list<string>, preview: array<string, mixed>}
     */
    public function preview(string $backupJson, RouterNode $targetRouter): array
    {
        try {
            /** @var array<string, mixed> $document */
            $document = json_decode($backupJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $throwable) {
            return ['ok' => false, 'errors' => ['Backup JSON is malformed.'], 'preview' => []];
        }

        $errors = [];
        if (($document['format'] ?? '') !== 'nurrouter-config-backup') {
            $errors[] = 'Unrecognized backup format.';
        }

        $version = (int) ($document['format_version'] ?? 0);
        if ($version < 1 || $version > self::FORMAT_VERSION) {
            $errors[] = "Incompatible backup format_version {$version}.";
        }

        if (isset($document['integrity']['value'], $document['integrity']['alg'])
            && $document['integrity']['alg'] === 'sha256') {
            $copy = $document;
            unset($copy['integrity']);
            $expected = hash('sha256', (string) json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if (! hash_equals((string) $document['integrity']['value'], $expected)) {
                $errors[] = 'Integrity checksum mismatch; backup may be altered.';
            }
        }

        if (isset($document['source']['device_id']) || isset($document['license'])) {
            $errors[] = 'Backup must not carry device identity or license ownership.';
        }

        $desired = (array) ($document['desired_configuration'] ?? []);
        $preview = [
            'source_router' => $document['source']['router_name'] ?? null,
            'source_namespace' => $document['source']['namespace_name'] ?? null,
            'target_router' => $targetRouter->name,
            'interfaces' => count($desired['interfaces'] ?? []),
            'addresses' => count($desired['addresses'] ?? []),
            'routes' => count($desired['routes'] ?? []),
            'pppoe_clients' => count($desired['services']['pppoe_clients'] ?? []),
            'secrets_included' => (bool) ($document['secrets_included'] ?? false),
            'mode_hints' => $document['mode_hints'] ?? [],
        ];

        return [
            'ok' => $errors === [] || (count($errors) === 1 && str_contains($errors[0], 'Integrity')),
            'errors' => $errors,
            'preview' => $preview,
            'document' => $document,
        ];
    }

    /**
     * @return array{revision_id: int}
     */
    public function restore(
        RouterNode $routerNode,
        string $backupJson,
        ?User $actor = null,
        bool $confirm = false,
    ): array {
        if (! $confirm) {
            throw new RuntimeException('Restore requires explicit confirmation.');
        }

        $preview = $this->preview($backupJson, $routerNode);
        if ($preview['errors'] !== [] && ! $preview['ok']) {
            throw new RuntimeException(implode(' ', $preview['errors']));
        }

        /** @var array<string, mixed> $document */
        $document = $preview['document'];
        $desired = (array) ($document['desired_configuration'] ?? []);
        unset($desired['license_entitlements']);

        $secrets = [];
        if (! empty($document['secrets_included']) && ! empty($document['desired_secret_configuration']['ciphertext'])) {
            $decoded = Crypt::decryptString((string) $document['desired_secret_configuration']['ciphertext']);
            $secrets = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        }

        // Snapshot current desired state for independent rollback if restore staging fails later.
        $rollbackToken = (string) Str::uuid();
        $rollbackPath = storage_path('app/nurrouter/restore-rollback/'.$rollbackToken.'.json');
        if (! is_dir(dirname($rollbackPath))) {
            mkdir(dirname($rollbackPath), 0750, true);
        }
        file_put_contents($rollbackPath, json_encode([
            'router_id' => $routerNode->id,
            'desired_configuration' => $routerNode->desired_configuration,
            'desired_secret_configuration' => $routerNode->desired_secret_configuration,
            'saved_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES));

        try {
            $this->entitlementGate->assertDesiredStateAllowed($routerNode, $desired, $secrets);
            $revision = app(RouterConfigurationManager::class)->updateDesiredState(
                $routerNode,
                $desired,
                $secrets,
                $actor,
            );
        } catch (\Throwable $throwable) {
            $rollback = json_decode((string) file_get_contents($rollbackPath), true);
            if (is_array($rollback)) {
                $routerNode->forceFill([
                    'desired_configuration' => $rollback['desired_configuration'] ?? [],
                    'desired_secret_configuration' => $rollback['desired_secret_configuration'] ?? [],
                ])->save();
            }

            throw new RuntimeException(
                'Restore failed; previous desired configuration was reinstated locally. '.$throwable->getMessage(),
                0,
                $throwable,
            );
        }

        $this->auditLogWriter->write(
            'router.backup.restored',
            "Restored configuration backup onto {$routerNode->namespace_name}.",
            $routerNode,
            $actor,
            metadata: [
                'revision_id' => $revision->id,
                'rollback_token' => $rollbackToken,
                'secrets_included' => (bool) ($document['secrets_included'] ?? false),
            ],
        );

        return ['revision_id' => $revision->id, 'rollback_token' => $rollbackToken];
    }
}
