<?php

namespace App\Services;

use RuntimeException;

class RouterAgentClient
{
    public function __construct(private readonly string $socketPath) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function call(string $operation, array $parameters = []): array
    {
        $connection = @stream_socket_client("unix://{$this->socketPath}", $errorCode, $errorMessage, 5);

        if ($connection === false) {
            throw new RuntimeException("Unable to connect to agent socket: {$errorMessage}");
        }

        fwrite($connection, json_encode([
            'operation' => $operation,
            'parameters' => $parameters,
        ], JSON_THROW_ON_ERROR)."\n");
        stream_socket_shutdown($connection, STREAM_SHUT_WR);

        $response = stream_get_contents($connection);
        fclose($connection);

        if ($response === false || $response === '') {
            throw new RuntimeException('Agent returned an empty response.');
        }

        /** @var array{success: bool, data?: array<string, mixed>, error?: array{message: string}} $decoded */
        $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);

        if (! $decoded['success']) {
            throw new RuntimeException($decoded['error']['message'] ?? 'Agent request failed.');
        }

        return $decoded['data'] ?? [];
    }

    /** @return array<string, mixed> */
    public function applyNamespace(string $namespaceName, string $interfaceName, string $managementCidr): array
    {
        return $this->call('namespace.apply', [
            'name' => $namespaceName,
            'interface_name' => $interfaceName,
            'management_cidr' => $managementCidr,
        ]);
    }

    /**
     * @param  array<string, mixed>  $desiredConfiguration
     * @return array<string, mixed>
     */
    public function applyRoutingState(string $namespaceName, array $desiredConfiguration): array
    {
        return $this->call('routing.apply', [
            'name' => $namespaceName,
            'desired_configuration' => $desiredConfiguration,
        ]);
    }

    /** @return array<string, mixed> */
    public function observeNamespace(string $namespaceName): array
    {
        return $this->call('namespace.observe', ['name' => $namespaceName]);
    }

    /** @return array<string, mixed> */
    public function observeRoutingState(string $namespaceName): array
    {
        return $this->call('routing.observe', ['name' => $namespaceName]);
    }

    /** @return array<string, mixed> */
    public function destroyNamespace(string $namespaceName): array
    {
        return $this->call('namespace.destroy', ['name' => $namespaceName]);
    }
}
