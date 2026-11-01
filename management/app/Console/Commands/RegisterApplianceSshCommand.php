<?php

namespace App\Console\Commands;

use App\Models\RouterNode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

#[Signature('nur:register-appliance-ssh
    {router : Router node ID or namespace name}
    {host? : Appliance SSH hostname or address}
    {--port=22 : Appliance SSH port}
    {--user=nur-mgmt : Appliance SSH username (forced-command identity)}
    {--private-key-file= : Path to the appliance SSH private key}
    {--known-host-file= : Path to a known_hosts entry for the appliance (required)}
    {--force-replace-host-key : Allow replacing an existing host-key pin after out-of-band console verification}
    {--clear : Remove the stored appliance SSH connection from the router node}')]
#[Description('Register or clear appliance SSH credentials for live OpenWrt management')]
class RegisterApplianceSshCommand extends Command
{
    public function handle(): int
    {
        $routerNode = $this->resolveRouterNode((string) $this->argument('router'));

        if ($this->option('clear')) {
            $routerNode->forceFill([
                'appliance_host' => null,
                'appliance_ssh_port' => null,
                'appliance_ssh_user' => null,
                'appliance_ssh_private_key' => null,
                'appliance_ssh_known_host' => null,
            ])->save();

            $this->components->info("Cleared appliance SSH connection for {$routerNode->namespace_name}.");

            return self::SUCCESS;
        }

        $host = (string) $this->argument('host');
        $privateKeyPath = (string) $this->option('private-key-file');
        $knownHostPath = (string) $this->option('known-host-file');

        if ($host === '' || $privateKeyPath === '' || $knownHostPath === '') {
            $this->components->error('Host, --private-key-file, and --known-host-file are required unless --clear is used. Changed host keys must never be accepted silently.');

            return self::FAILURE;
        }

        try {
            $privateKey = $this->readRequiredFile($privateKeyPath, 'private key');
            $knownHost = $this->readRequiredFile($knownHostPath, 'known host');
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $existingPin = $routerNode->appliance_ssh_known_host;
        if (filled($existingPin) && trim((string) $existingPin) !== trim($knownHost)) {
            $this->components->warn('Replacing a previously enrolled host-key pin requires out-of-band console verification (nur-console-enroll status).');
            if ($this->option('force-replace-host-key')) {
                $this->components->warn('Proceeding because --force-replace-host-key was provided.');
            } elseif ($this->option('no-interaction')) {
                $this->components->error('Refusing silent host-key replacement in non-interactive mode. Re-verify on console, then pass --force-replace-host-key.');

                return self::FAILURE;
            } elseif (! $this->confirm('Replace the enrolled host-key pin?', false)) {
                $this->components->error('Aborted. Existing pin retained; changed host keys are never accepted silently.');

                return self::FAILURE;
            }
        }

        $routerNode->forceFill([
            'appliance_host' => $host,
            'appliance_ssh_port' => (int) $this->option('port'),
            'appliance_ssh_user' => (string) $this->option('user'),
            'appliance_ssh_private_key' => $privateKey,
            'appliance_ssh_known_host' => $knownHost,
        ])->save();

        $this->components->info("Registered appliance SSH connection for {$routerNode->namespace_name}.");
        $this->line("Host: {$host}:{$routerNode->appliance_ssh_port}");
        $this->line("User: {$routerNode->appliance_ssh_user}");
        $this->line('Known host pinning: stored from file (StrictHostKeyChecking=yes)');

        return self::SUCCESS;
    }

    private function resolveRouterNode(string $routerIdentifier): RouterNode
    {
        return RouterNode::query()
            ->when(
                ctype_digit($routerIdentifier),
                fn ($query) => $query->whereKey((int) $routerIdentifier),
                fn ($query) => $query->where('namespace_name', $routerIdentifier),
            )
            ->firstOrFail();
    }

    private function readRequiredFile(string $path, string $label): string
    {
        if (! File::isFile($path) || ! File::isReadable($path)) {
            throw new RuntimeException("The {$label} file [{$path}] is not readable.");
        }

        $contents = File::get($path);

        if (trim($contents) === '') {
            throw new RuntimeException("The {$label} file [{$path}] is empty.");
        }

        return $contents;
    }

    private function readOptionalFile(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        return $this->readRequiredFile($path, 'known host');
    }
}
