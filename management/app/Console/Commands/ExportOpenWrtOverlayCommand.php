<?php

namespace App\Console\Commands;

use App\Models\RouterNode;
use App\Services\OpenWrtOverlayExporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('nur:export-openwrt-overlay
    {router : Router node ID or namespace name}
    {--source=applied : Configuration source to export (applied or desired)}
    {--include-secrets=0 : Include plaintext appliance secrets in the exported overlay}
    {--target= : Absolute or relative overlay directory target}')]
#[Description('Export a router node configuration into an OpenWrt overlay directory')]
class ExportOpenWrtOverlayCommand extends Command
{
    public function __construct(private readonly OpenWrtOverlayExporter $overlayExporter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $router = $this->resolveRouterNode((string) $this->argument('router'));
        $source = (string) $this->option('source');
        $target = (string) ($this->option('target') ?: base_path('../files'));
        $includeSecrets = in_array((string) $this->option('include-secrets'), ['1', 'true', 'yes'], true);

        if (! in_array($source, ['applied', 'desired'], true)) {
            $this->components->error('The --source option must be either applied or desired.');

            return self::FAILURE;
        }

        try {
            $paths = $this->overlayExporter->export($router, $target, $source, $includeSecrets);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Exported {$source} OpenWrt overlay for {$router->namespace_name}.");
        $this->line("Network: {$paths['network']}");
        $this->line("DHCP/DNS: {$paths['dhcp']}");
        $this->line("Manifest: {$paths['manifest']}");

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
}
