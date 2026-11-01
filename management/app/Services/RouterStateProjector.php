<?php

namespace App\Services;

use App\Models\RouterNode;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class RouterStateProjector
{
    public function __construct(private readonly OpenWrtRuntimeConfigRenderer $runtimeConfigRenderer) {}

    /**
     * @return array<string, mixed>
     */
    public function project(RouterNode $routerNode): array
    {
        $routerNode->loadMissing(['latestObservedState', 'revisions.user', 'auditLogs.user']);

        $observedState = $routerNode->latestObservedState;
        $observedPayload = $observedState?->state ?? [];

        return [
            'router' => [
                'id' => $routerNode->id,
                'name' => $routerNode->name,
                'namespace' => $routerNode->namespace_name,
                'management_interface' => $routerNode->management_interface,
                'management_cidr' => $routerNode->management_cidr,
                'status' => $routerNode->status,
                'configuration_lock_version' => $routerNode->configuration_lock_version,
            ],
            'safe_mode' => [
                'status' => $routerNode->safe_mode_status,
                'confirm_by' => $routerNode->safe_mode_confirm_by?->toIso8601String(),
                'pending_operation_uuid' => $routerNode->pending_operation_uuid,
                'reconciliation_deadline_at' => $routerNode->reconciliation_deadline_at?->toIso8601String(),
            ],
            'desired' => [
                'revision_id' => $routerNode->desired_revision_id,
                'configuration' => $routerNode->desired_configuration ?? [],
                'normalized' => $this->normalizeConfiguration(
                    $routerNode->desired_configuration ?? [],
                    $routerNode->desired_secret_configuration ?? [],
                ),
                'runtime_preview' => $this->runtimeConfigRenderer->render(
                    $routerNode->desired_configuration ?? [],
                    $routerNode->desired_secret_configuration ?? [],
                ),
            ],
            'applied' => [
                'revision_id' => $routerNode->applied_revision_id,
                'configuration' => $routerNode->applied_configuration ?? [],
                'normalized' => $this->normalizeConfiguration(
                    $routerNode->applied_configuration ?? [],
                    $routerNode->applied_secret_configuration ?? [],
                ),
                'runtime_preview' => $this->runtimeConfigRenderer->render(
                    $routerNode->applied_configuration ?? [],
                    $routerNode->applied_secret_configuration ?? [],
                ),
            ],
            'observed' => [
                'recorded_at' => $observedState?->recorded_at?->toIso8601String(),
                'normalized' => $this->normalizeObservedState($observedPayload),
                'raw' => $observedPayload,
            ],
            'diff' => [
                'desired_vs_applied' => $this->diffConfigurations(
                    $routerNode->applied_configuration ?? [],
                    $routerNode->desired_configuration ?? [],
                ),
            ],
            'revisions' => $routerNode->revisions
                ->sortByDesc('revision_number')
                ->values()
                ->map(fn ($revision): array => [
                    'id' => $revision->id,
                    'revision_number' => $revision->revision_number,
                    'apply_status' => $revision->apply_status,
                    'created_at' => $revision->created_at?->toIso8601String(),
                    'applied_at' => $revision->applied_at?->toIso8601String(),
                    'confirm_by' => $revision->confirm_by?->toIso8601String(),
                    'confirmed_at' => $revision->confirmed_at?->toIso8601String(),
                    'failure_reason' => $revision->failure_reason,
                    'diff_summary' => $revision->diff_summary,
                    'user' => $revision->user?->only(['id', 'name', 'email']),
                ])
                ->all(),
            'audit' => $routerNode->auditLogs
                ->sortByDesc('created_at')
                ->values()
                ->map(fn ($auditLog): array => [
                    'id' => $auditLog->id,
                    'created_at' => $auditLog->created_at?->toIso8601String(),
                    'action' => $auditLog->action,
                    'summary' => $auditLog->summary,
                    'status' => $auditLog->status,
                    'metadata' => $auditLog->metadata,
                    'user' => $auditLog->user?->only(['id', 'name', 'email']),
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $secretConfiguration
     * @return array<string, mixed>
     */
    private function normalizeConfiguration(array $configuration, array $secretConfiguration = []): array
    {
        $interfaces = collect(Arr::get($configuration, 'interfaces', []));
        $addresses = collect(Arr::get($configuration, 'addresses', []));
        $pppoeClients = collect(Arr::get($configuration, 'services.pppoe_clients', []))
            ->map(function (array $client) use ($secretConfiguration): array {
                $name = (string) ($client['name'] ?? '');

                return [
                    ...$client,
                    'has_password' => data_get($secretConfiguration, "services.pppoe_clients.{$name}.password") !== null,
                ];
            })
            ->values()
            ->all();

        if (Arr::has($configuration, 'management.interface')) {
            $interfaces->prepend([
                'name' => Arr::get($configuration, 'management.interface'),
                'type' => 'management',
                'state' => 'up',
            ]);
        }

        if (Arr::has($configuration, 'management.cidr')) {
            $addresses->prepend([
                'interface' => Arr::get($configuration, 'management.interface'),
                'cidr' => Arr::get($configuration, 'management.cidr'),
            ]);
        }

        return [
            'interfaces' => $interfaces->values()->all(),
            'addresses' => $addresses->values()->all(),
            'routes' => Arr::get($configuration, 'routes', []),
            'bridge_vlans' => Arr::get($configuration, 'bridge_vlans', []),
            'forwarding' => Arr::get($configuration, 'forwarding', []),
            'services' => [
                'dns' => [
                    'forwarders' => Arr::get($configuration, 'services.dns.forwarders', []),
                    'search_domains' => Arr::get($configuration, 'services.dns.search_domains', []),
                ],
                'dhcp_servers' => Arr::get($configuration, 'services.dhcp_servers', []),
                'pppoe_clients' => $pppoeClients,
            ],
            'firewall' => Arr::get($configuration, 'firewall', []),
        ];
    }

    /**
     * @param  array<string, mixed>  $observedPayload
     * @return array<string, mixed>
     */
    private function normalizeObservedState(array $observedPayload): array
    {
        $interfaces = collect(Arr::get($observedPayload, 'links', []))
            ->map(function (array $link): array {
                return [
                    'name' => Arr::get($link, 'ifname'),
                    'type' => Arr::get($link, 'linkinfo.info_kind', 'device'),
                    'state' => Arr::get($link, 'operstate', 'unknown'),
                    'master' => Arr::get($link, 'master'),
                    'mtu' => Arr::get($link, 'mtu'),
                ];
            })
            ->values();

        $addresses = collect(Arr::get($observedPayload, 'addresses', []))
            ->flatMap(function (array $link): Collection {
                return collect(Arr::get($link, 'addr_info', []))
                    ->map(function (array $address) use ($link): array {
                        return [
                            'interface' => Arr::get($link, 'ifname'),
                            'family' => Arr::get($address, 'family'),
                            'local' => Arr::get($address, 'local'),
                            'prefixlen' => Arr::get($address, 'prefixlen'),
                            'scope' => Arr::get($address, 'scope'),
                            'cidr' => Arr::get($address, 'local') !== null
                                ? Arr::get($address, 'local').'/'.Arr::get($address, 'prefixlen')
                                : null,
                        ];
                    });
            })
            ->values();

        $routes = collect(Arr::get($observedPayload, 'routes', []))
            ->map(fn (array $route): array => [
                'destination' => Arr::get($route, 'dst', 'default'),
                'gateway' => Arr::get($route, 'gateway'),
                'device' => Arr::get($route, 'dev'),
                'protocol' => Arr::get($route, 'protocol'),
                'scope' => Arr::get($route, 'scope'),
            ])
            ->values();

        $traffic = collect(Arr::get($observedPayload, 'link_stats', Arr::get($observedPayload, 'links', [])))
            ->map(function (array $link): array {
                return [
                    'name' => Arr::get($link, 'ifname'),
                    'operstate' => Arr::get($link, 'operstate'),
                    'rx_bytes' => Arr::get($link, 'stats64.rx.bytes', Arr::get($link, 'stats.rx.bytes')),
                    'tx_bytes' => Arr::get($link, 'stats64.tx.bytes', Arr::get($link, 'stats.tx.bytes')),
                    'rx_packets' => Arr::get($link, 'stats64.rx.packets', Arr::get($link, 'stats.rx.packets')),
                    'tx_packets' => Arr::get($link, 'stats64.tx.packets', Arr::get($link, 'stats.tx.packets')),
                ];
            })
            ->filter(fn (array $row): bool => filled($row['name']))
            ->values();

        $firewallCounters = collect(Arr::get($observedPayload, 'firewall.nftables', Arr::get($observedPayload, 'firewall', [])))
            ->flatten(1)
            ->filter(fn ($entry): bool => is_array($entry) && Arr::has($entry, 'rule') && Arr::has($entry, 'counter'))
            ->map(fn (array $entry): array => [
                'chain' => Arr::get($entry, 'rule.chain', Arr::get($entry, 'chain')),
                'comment' => Arr::get($entry, 'rule.comment'),
                'packets' => Arr::get($entry, 'counter.packets'),
                'bytes' => Arr::get($entry, 'counter.bytes'),
            ])
            ->values();

        return [
            'interfaces' => $interfaces->all(),
            'addresses' => $addresses->all(),
            'routes' => $routes->all(),
            'bridge_vlans' => Arr::get($observedPayload, 'bridge_vlans', []),
            'firewall' => Arr::get($observedPayload, 'firewall', []),
            'firewall_counters' => $firewallCounters->all(),
            'forwarding' => Arr::get($observedPayload, 'forwarding', []),
            'traffic' => $traffic->all(),
            'dhcp_leases' => Arr::get($observedPayload, 'services.dhcp_leases', []),
            'pppoe_sessions' => Arr::get($observedPayload, 'services.pppoe_sessions', []),
            'services' => Arr::get($observedPayload, 'services', []),
            'observed_at' => Arr::get($observedPayload, 'observed_at'),
        ];
    }

    /**
     * @param  array<string, mixed>  $applied
     * @param  array<string, mixed>  $desired
     * @return array<int, array<string, mixed>>
     */
    private function diffConfigurations(array $applied, array $desired, string $path = ''): array
    {
        $diff = [];
        $keys = collect(array_keys($applied))
            ->merge(array_keys($desired))
            ->unique()
            ->sort()
            ->values();

        foreach ($keys as $key) {
            $currentPath = $path === '' ? (string) $key : "{$path}.{$key}";
            $appliedValue = $applied[$key] ?? null;
            $desiredValue = $desired[$key] ?? null;

            if (is_array($appliedValue) && is_array($desiredValue)) {
                $diff = [...$diff, ...$this->diffConfigurations($appliedValue, $desiredValue, $currentPath)];

                continue;
            }

            if ($appliedValue !== $desiredValue) {
                $diff[] = [
                    'path' => $currentPath,
                    'applied' => $appliedValue,
                    'desired' => $desiredValue,
                ];
            }
        }

        return $diff;
    }
}
