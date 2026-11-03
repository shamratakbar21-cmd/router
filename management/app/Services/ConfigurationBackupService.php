<?php

namespace App\Services;

use App\Models\RouterNode;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ConfigurationBackupService
{
    public const FORMAT_VERSION = 2;

    public function __construct(
        private readonly AuditLogWriter $auditLogWriter,
        private readonly RouterConfigurationValidator $validator,
    ) {}

    public function export(RouterNode $routerNode, ?User $actor = null, bool $includeSecrets = false): array
    {
        $desired = $routerNode->desired_configuration ?? [];
        unset($desired['license_entitlements']);
        $document = [
            'format' => 'nurrouter-config-backup', 'format_version' => self::FORMAT_VERSION,
            'exported_at' => now()->toIso8601String(),
            'source' => ['router_name' => $routerNode->name, 'namespace_name' => $routerNode->namespace_name],
            'desired_configuration' => $desired,
            'secrets_included' => $includeSecrets,
            'desired_secret_configuration' => $includeSecrets ? ($routerNode->desired_secret_configuration ?? []) : null,
        ];
        if ($includeSecrets) {
            // Authenticate the complete document, including its public configuration.
            $document = [
                'format' => 'nurrouter-config-backup', 'format_version' => self::FORMAT_VERSION,
                'encrypted_payload' => Crypt::encryptString($this->encode($document)),
            ];
        }
        $integrity = hash('sha256', $this->encode($document));
        $document['integrity'] = ['alg' => 'sha256', 'value' => $integrity];
        $this->auditLogWriter->write('router.backup.exported', 'Exported a versioned configuration backup.', $routerNode, $actor, metadata: ['format_version' => self::FORMAT_VERSION, 'secrets_included' => $includeSecrets]);

        return ['document' => $document, 'json' => json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'integrity' => $integrity];
    }

    public function preview(string $backupJson, RouterNode $targetRouter, bool $allowLegacy = false): array
    {
        try {
            if (strlen($backupJson) > 8 * 1024 * 1024) {
                throw new RuntimeException('Backup exceeds the 8 MiB import limit.');
            }
            $document = json_decode($backupJson, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($document) || array_is_list($document)) {
                throw new RuntimeException('Backup must be a JSON object.');
            }
            if (($document['format'] ?? '') !== 'nurrouter-config-backup') {
                throw new RuntimeException('Unrecognized backup format.');
            }
            $version = $document['format_version'] ?? null;
            if (! in_array($version, [1, self::FORMAT_VERSION], true)) {
                throw new RuntimeException('Unsupported backup version.');
            }
            if ($version === 1 && ! $allowLegacy) {
                throw new RuntimeException('Version 1 requires explicit legacy import; verify its source and retain the original APP_KEY for encrypted secrets.');
            }
            $integrity = $document['integrity'] ?? null;
            unset($document['integrity']);
            if (! is_array($integrity) || ($integrity['alg'] ?? '') !== 'sha256' || ! is_string($integrity['value'] ?? null)
                || ! hash_equals(hash('sha256', $this->encode($document)), $integrity['value'])) {
                throw new RuntimeException('Integrity checksum mismatch or missing checksum.');
            }
            if (isset($document['encrypted_payload'])) {
                $document = json_decode(Crypt::decryptString($document['encrypted_payload']), true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($document) || ($document['format'] ?? '') !== 'nurrouter-config-backup' || ($document['format_version'] ?? null) !== self::FORMAT_VERSION || ($document['secrets_included'] ?? null) !== true) {
                    throw new RuntimeException('Invalid authenticated backup payload.');
                }
            } elseif ($version === self::FORMAT_VERSION && ($document['secrets_included'] ?? false)) {
                throw new RuntimeException('Secret-bearing backups require an authenticated encrypted payload.');
            }
            if (isset($document['source']['device_id']) || isset($document['license']) || isset($document['device_id'])) {
                throw new RuntimeException('Backups cannot contain device identity or license ownership.');
            }
            if (! is_array($document['desired_configuration'] ?? null) || ! is_bool($document['secrets_included'] ?? null)) {
                throw new RuntimeException('Invalid backup schema.');
            }
            $secrets = [];
            if ($document['secrets_included']) {
                $secrets = $version === 1
                    ? json_decode(Crypt::decryptString($document['desired_secret_configuration']['ciphertext'] ?? ''), true, 64, JSON_THROW_ON_ERROR)
                    : ($document['desired_secret_configuration'] ?? []);
                if (! is_array($secrets)) {
                    throw new RuntimeException('Invalid secret configuration.');
                }
            }
            $desired = $document['desired_configuration'];
            unset($desired['license_entitlements']);
            $this->validator->validate($desired, $targetRouter, $secrets);
            $document['desired_configuration'] = $desired;
            $document['desired_secret_configuration'] = $secrets;

            return ['ok' => true, 'errors' => [], 'document' => $document, 'preview' => [
                'source_router' => $document['source']['router_name'] ?? null,
                'target_router' => $targetRouter->name,
                'interfaces' => count($desired['interfaces'] ?? []), 'addresses' => count($desired['addresses'] ?? []),
                'routes' => count($desired['routes'] ?? []), 'secrets_included' => $document['secrets_included'],
                'legacy_import' => $version === 1,
            ]];
        } catch (Throwable $exception) {
            $message = $exception instanceof ValidationException ? implode(' ', $exception->validator->errors()->all()) : $exception->getMessage();

            return ['ok' => false, 'errors' => ['Backup rejected. '.$message], 'preview' => []];
        }
    }

    public function restore(RouterNode $routerNode, string $backupJson, ?User $actor = null, bool $confirm = false, bool $allowLegacy = false): array
    {
        if (! $confirm) {
            throw new RuntimeException('Restore requires explicit confirmation.');
        }
        $preview = $this->preview($backupJson, $routerNode, $allowLegacy);
        if (! $preview['ok']) {
            throw new RuntimeException(implode(' ', $preview['errors']));
        }
        $document = $preview['document'];

        return DB::transaction(function () use ($routerNode, $document, $actor): array {
            $router = RouterNode::query()->lockForUpdate()->findOrFail($routerNode->id);
            if ($router->pending_operation_uuid || $router->safe_mode_status === 'pending_confirm') {
                throw new RuntimeException('Finish the pending operation before restoring a backup.');
            }
            $revision = app(RouterConfigurationManager::class)->updateDesiredState($router, $document['desired_configuration'], $document['desired_secret_configuration'], $actor);
            $this->auditLogWriter->write('router.backup.restored', 'Validated backup restored into desired state; apply remains separate.', $router, $actor, metadata: ['revision_id' => $revision->id]);

            return ['revision_id' => $revision->id];
        }, attempts: 5);
    }

    private function encode(array $document): string
    {
        return json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
