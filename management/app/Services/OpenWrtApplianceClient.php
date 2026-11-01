<?php

namespace App\Services;

use App\Exceptions\ApplianceTransportException;
use App\Models\RouterNode;
use Illuminate\Support\Facades\File;
use JsonException;

/**
 * SSH transport from external NUR WinControl to a booted NurRouterOS appliance.
 *
 * This is intentionally not an mTLS HTTP API. The control plane authenticates with a
 * dedicated management key, pins the appliance host key, and runs only typed
 * nur-runtimectl operations (or a constrained cleanup) over OpenSSH/Dropbear.
 */
class OpenWrtApplianceClient
{
    public function __construct(private readonly OpenWrtOverlayExporter $overlayExporter) {}

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $secretConfiguration
     * @return array<string, mixed>
     */
    public function applyConfiguration(
        RouterNode $routerNode,
        array $configuration,
        array $secretConfiguration,
        string $operationUuid,
        ?int $safeModeMinutes = null,
    ): array {
        $workingRouterNode = clone $routerNode;
        $workingRouterNode->desired_configuration = $configuration;
        $workingRouterNode->desired_secret_configuration = $secretConfiguration;

        $remoteOverlayDirectory = "/tmp/nurrouter-overlay-{$operationUuid}";
        $localOverlayDirectory = storage_path('app/nurrouter/overlays/'.basename($remoteOverlayDirectory));

        File::deleteDirectory($localOverlayDirectory);
        $this->overlayExporter->export($workingRouterNode, $localOverlayDirectory, 'desired', true);

        try {
            // SCP into the exact overlay path so nur-mgmt-ssh can allowlist
            // `scp … -t /tmp/nurrouter-overlay-*` (never a broad /tmp sink).
            $this->runScp($routerNode, $localOverlayDirectory, $remoteOverlayDirectory);

            $output = $this->runTypedSsh(
                $routerNode,
                ApplianceSshOperation::apply(
                    $remoteOverlayDirectory,
                    $operationUuid,
                    ($safeModeMinutes ?? 0) * 60,
                ),
            );
        } finally {
            File::deleteDirectory($localOverlayDirectory);

            try {
                $this->runTypedSsh($routerNode, ApplianceSshOperation::cleanupOverlay($remoteOverlayDirectory));
            } catch (ApplianceTransportException) {
                // Best-effort cleanup; apply outcome is determined by the apply call.
            }
        }

        return $this->decodeJsonPayload($output, ApplianceSshOperation::APPLY);
    }

    /**
     * @return array<string, mixed>
     */
    public function observeConfiguration(RouterNode $routerNode): array
    {
        return $this->decodeJsonPayload(
            $this->runTypedSsh($routerNode, ApplianceSshOperation::observe()),
            ApplianceSshOperation::OBSERVE,
        );
    }

    public function confirmSafeMode(RouterNode $routerNode, string $operationUuid): void
    {
        $this->runTypedSsh($routerNode, ApplianceSshOperation::confirm($operationUuid));
    }

    private function runScp(RouterNode $routerNode, string $localSource, string $remoteTarget): void
    {
        ['scp_options' => $options, 'cleanup' => $cleanup] = $this->sshContext($routerNode);

        try {
            $this->runProcess([
                'scp',
                ...$options,
                '-r',
                $localSource,
                $this->remoteTarget($routerNode, $remoteTarget),
            ], 'scp');
        } finally {
            $this->cleanupTempFiles($cleanup);
        }
    }

    private function runTypedSsh(RouterNode $routerNode, ApplianceSshOperation $operation): string
    {
        ['ssh_options' => $options, 'cleanup' => $cleanup] = $this->sshContext($routerNode);

        try {
            return $this->runProcess([
                'ssh',
                ...$options,
                $this->remoteTarget($routerNode),
                $operation->remoteCommand(),
            ], $operation->name);
        } finally {
            $this->cleanupTempFiles($cleanup);
        }
    }

    /**
     * @return array{
     *     ssh_options: array<int, string>,
     *     scp_options: array<int, string>,
     *     cleanup: array<int, string>
     * }
     */
    private function sshContext(RouterNode $routerNode): array
    {
        if (! $routerNode->usesApplianceConnection()) {
            throw new ApplianceTransportException(
                ApplianceTransportException::KIND_NOT_ENROLLED,
                "Router {$routerNode->namespace_name} is not configured for appliance SSH access.",
            );
        }

        if (! filled($routerNode->appliance_ssh_known_host)) {
            throw new ApplianceTransportException(
                ApplianceTransportException::KIND_NOT_ENROLLED,
                "Router {$routerNode->namespace_name} has no enrolled appliance host-key pin.",
            );
        }

        $keyPath = $this->writeTempFile((string) $routerNode->appliance_ssh_private_key, 0600);
        $knownHostsPath = $this->writeTempFile((string) $routerNode->appliance_ssh_known_host, 0600);
        $cleanup = [$keyPath, $knownHostsPath];

        $common = [
            '-o', 'BatchMode=yes',
            '-o', 'IdentitiesOnly=yes',
            '-o', 'ConnectTimeout=10',
            '-o', 'ServerAliveInterval=5',
            '-o', 'ServerAliveCountMax=2',
            '-o', 'StrictHostKeyChecking=yes',
            '-o', "UserKnownHostsFile={$knownHostsPath}",
            '-o', 'GlobalKnownHostsFile=/dev/null',
            '-o', 'ClearAllForwardings=yes',
            '-o', 'ForwardAgent=no',
            '-o', 'ForwardX11=no',
            '-o', 'PermitLocalCommand=no',
            '-o', 'RequestTTY=no',
        ];

        return [
            'ssh_options' => [
                '-i', $keyPath,
                '-p', (string) ($routerNode->appliance_ssh_port ?? 22),
                ...$common,
            ],
            'scp_options' => [
                '-O',
                '-i', $keyPath,
                '-P', (string) ($routerNode->appliance_ssh_port ?? 22),
                ...$common,
            ],
            'cleanup' => $cleanup,
        ];
    }

    private function remoteTarget(RouterNode $routerNode, ?string $path = null): string
    {
        $user = $routerNode->appliance_ssh_user ?: 'nur-mgmt';
        $target = sprintf('%s@%s', $user, $routerNode->appliance_host);

        return $path === null ? $target : "{$target}:{$path}";
    }

    /**
     * @param  array<int, string>  $command
     */
    private function runProcess(array $command, string $operation): string
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if (! is_resource($process)) {
            throw new ApplianceTransportException(
                ApplianceTransportException::KIND_UNREACHABLE,
                'Unable to start SSH appliance command.',
                ['operation' => $operation],
            );
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw ApplianceTransportException::fromProcessFailure(
                (string) $stderr,
                $exitCode,
                $operation,
            );
        }

        return (string) $stdout;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonPayload(string $output, string $operation): array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApplianceTransportException(
                ApplianceTransportException::KIND_MALFORMED_RESPONSE,
                'Appliance returned non-JSON output for '.$operation.'.',
                ['operation' => $operation],
                $exception,
            );
        }

        return $decoded;
    }

    private function writeTempFile(string $contents, int $permissions): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nurrouter-');

        if ($path === false) {
            throw new ApplianceTransportException(
                ApplianceTransportException::KIND_UNREACHABLE,
                'Unable to allocate a temporary SSH file.',
            );
        }

        file_put_contents($path, $contents);
        chmod($path, $permissions);

        return $path;
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function cleanupTempFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
