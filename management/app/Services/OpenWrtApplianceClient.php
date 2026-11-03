<?php

namespace App\Services;

use App\Exceptions\ApplianceTransportException;
use App\Models\RouterNode;
use Illuminate\Support\Facades\File;
use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * SSH transport from external NUR WinControl to a booted NurRouterOS appliance.
 *
 * This is intentionally not an mTLS HTTP API. The control plane authenticates with a
 * dedicated management key, pins the appliance host key, and runs only typed
 * nur-runtimectl operations (or a constrained cleanup) over OpenSSH/Dropbear.
 */
class OpenWrtApplianceClient
{
    public function __construct(
        private readonly OpenWrtOverlayExporter $overlayExporter,
        private readonly float $commandTimeout = 120,
    ) {}

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
        $needsNeighborProof = ($configuration['static_neighbors'] ?? []) !== []
            || ($routerNode->applied_configuration['static_neighbors'] ?? []) !== [];
        $neighborState = $needsNeighborProof ? $this->observeConfiguration($routerNode) : [];
        if ($needsNeighborProof && ! RouterStateProjector::supportsNeighborManagement($neighborState)) {
            throw new ApplianceTransportException(
                ApplianceTransportException::KIND_COMMAND_REJECTED,
                'This runtime has not negotiated a verified neighbor API. Managed ARP changes cannot be verified; no configuration was uploaded or applied.',
            );
        }

        $workingRouterNode = clone $routerNode;
        $workingRouterNode->desired_configuration = $configuration;
        $workingRouterNode->desired_secret_configuration = $secretConfiguration;

        $remoteOverlayDirectory = "/tmp/nurrouter-overlay-{$operationUuid}";
        $applyOperation = ApplianceSshOperation::apply($remoteOverlayDirectory, $operationUuid, ($safeModeMinutes ?? 0) * 60);
        $localOverlayDirectory = storage_path('app/nurrouter/overlays/'.basename($remoteOverlayDirectory));

        File::deleteDirectory($localOverlayDirectory);
        try {
            $this->overlayExporter->export($workingRouterNode, $localOverlayDirectory, 'desired', true);
            $brasHash = data_get($configuration, 'services.pppoe_server.enabled', false)
                ? hash_file('sha256', $localOverlayDirectory.'/etc/nurrouter/bras.json')
                : null;

            // SCP into the exact overlay path so nur-mgmt-ssh can allowlist
            // `scp … -t /tmp/nurrouter-overlay-*` (never a broad /tmp sink).
            $this->runScp($routerNode, $localOverlayDirectory, $remoteOverlayDirectory);

            $output = $this->runTypedSsh(
                $routerNode,
                $applyOperation,
            );
        } finally {
            File::deleteDirectory($localOverlayDirectory);

            try {
                $this->runTypedSsh($routerNode, ApplianceSshOperation::cleanupOverlay($remoteOverlayDirectory));
            } catch (ApplianceTransportException) {
                // Best-effort cleanup; apply outcome is determined by the apply call.
            }
        }

        $response = $this->decodeJsonPayload($output, ApplianceSshOperation::APPLY);

        // Appliance detaches network reload so management SSH can return immediately.
        // Poll observe until the plane recovers (or give up with the ack payload).
        if (($response['phase'] ?? null) === 'reloading') {
            $deadline = microtime(true) + 180;
            for ($attempt = 0; $attempt < 36 && microtime(true) < $deadline; $attempt++) {
                sleep(5);

                try {
                    $observed = $this->observeConfiguration($routerNode);
                    $operationState = $observed['apply_operations'][$operationUuid] ?? null;
                    if (in_array($operationState, ['failed', 'rolled_back'], true)) {
                        throw new ApplianceTransportException(
                            ApplianceTransportException::KIND_APPLY_FAILED,
                            'The appliance rejected or rolled back the apply operation.',
                        );
                    }
                    if ($operationState === 'applied' && $this->matchesRequestedState($configuration, $observed, $brasHash, $routerNode->applied_configuration['static_neighbors'] ?? [])) {
                        $observed['status'] = 'applied';
                        $observed['phase'] = 'observed_after_reload';
                        $observed['operation_id'] = $operationUuid;

                        return $observed;
                    }
                } catch (ApplianceTransportException $exception) {
                    if ($exception->kind === ApplianceTransportException::KIND_APPLY_FAILED) {
                        throw $exception;
                    }
                    // Management plane still settling after reload.
                }
            }
            throw new ApplianceTransportException(
                ApplianceTransportException::KIND_TIMEOUT,
                'The appliance did not report completion of the apply operation.',
            );
        }

        return $response;
    }

    /** Verify observable requested devices, addresses, routes and PPPoE state. */
    private function matchesRequestedState(array $configuration, array $observed, ?string $brasHash = null, array $previousNeighbors = []): bool
    {
        if (! ($observed['services']['dnsmasq_running'] ?? false)) {
            return false;
        }
        if (data_get($configuration, 'services.pppoe_server.enabled', false)) {
            $server = data_get($observed, 'services.pppoe_server', []);
            if (($server['state'] ?? '') !== 'running' || ! hash_equals($brasHash ?? '', $server['configuration_sha256'] ?? '')) {
                return false;
            }
            foreach (data_get($configuration, 'services.pppoe_server.access_interfaces', []) as $interface) {
                if (! str_contains($server['access_interfaces_table'] ?? '', ' '.$interface.' ')) {
                    return false;
                }
            }
        }
        $links = array_column($observed['links'] ?? [], 'ifname');
        foreach ($configuration['interfaces'] ?? [] as $interface) {
            if (! in_array($interface['name'], $links, true)) {
                return false;
            }
        }
        $addresses = $configuration['addresses'] ?? [];
        if (! empty($configuration['management']['cidr'])) {
            $addresses[] = ['interface' => $configuration['management']['interface'], 'cidr' => $configuration['management']['cidr']];
        }
        foreach ($addresses as $address) {
            [$ip, $prefix] = explode('/', $address['cidr']);
            $found = false;
            foreach ($observed['addresses'] ?? [] as $device) {
                if ($device['ifname'] !== $address['interface']) {
                    continue;
                }
                foreach ($device['addr_info'] ?? [] as $actual) {
                    $found = $found || ($actual['local'] === $ip && (int) $actual['prefixlen'] === (int) $prefix);
                }
            }
            if (! $found) {
                return false;
            }
        }
        foreach ($configuration['routes'] ?? [] as $route) {
            $found = false;
            foreach ($observed['routes'] ?? [] as $actual) {
                $destination = $route['destination'] === '0.0.0.0/0' ? 'default' : $route['destination'];
                if (($actual['dst'] ?? '') === $destination && (empty($route['gateway']) || ($actual['gateway'] ?? '') === $route['gateway'])) {
                    $found = true;
                }
            }
            if (! $found) {
                return false;
            }
        }
        if (! NeighborCapabilities::verifiesConfiguration($configuration['static_neighbors'] ?? [], $observed, $previousNeighbors)) {
            return false;
        }
        foreach ($configuration['services']['pppoe_clients'] ?? [] as $client) {
            $found = false;
            $name = str_replace(['-', '.'], '_', $client['name']);
            foreach ($observed['services']['pppoe_sessions'] ?? [] as $session) {
                $found = $found || ($session['name'] === $name && $session['up'] === true);
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnose(RouterNode $routerNode, string $tool, string $target): array
    {
        return $this->decodeJsonPayload($this->runTypedSsh($routerNode, ApplianceSshOperation::diagnose($tool, $target)), ApplianceSshOperation::DIAGNOSE);
    }

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
                '--',
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
                '--',
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
            '-o', 'ConnectTimeout=15',
            '-o', 'ServerAliveInterval=5',
            // Survive brief management-plane blips during network reload on apply.
            '-o', 'ServerAliveCountMax=24',
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
        $host = (string) $routerNode->appliance_host;
        $address = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
        $isIp = filter_var($address, FILTER_VALIDATE_IP) !== false;
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,63}$/D', $user)
            || (! $isIp && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,254}$/D', $host))) {
            throw new ApplianceTransportException(
                ApplianceTransportException::KIND_COMMAND_REJECTED,
                'Rejected unsafe appliance SSH target.',
            );
        }
        $host = $path !== null && str_contains($address, ':') ? "[{$address}]" : $address;
        $target = sprintf('%s@%s', $user, $host);

        return $path === null ? $target : "{$target}:{$path}";
    }

    /**
     * @param  array<int, string>  $command
     */
    private function runProcess(array $command, string $operation): string
    {
        $process = new Process($command);
        $process->setTimeout($this->commandTimeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            throw new ApplianceTransportException(
                ApplianceTransportException::KIND_TIMEOUT,
                'The appliance SSH command exceeded its time limit.',
                ['operation' => $operation],
                $exception,
            );
        }

        if (! $process->isSuccessful()) {
            throw ApplianceTransportException::fromProcessFailure(
                $process->getErrorOutput(),
                $process->getExitCode() ?? 1,
                $operation,
            );
        }

        return $process->getOutput();
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
