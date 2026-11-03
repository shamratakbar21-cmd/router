<?php

namespace App\Services;

use App\Models\RouterNode;

class NeighborCapabilities
{
    public const OPERATIONS = ['observe', 'add', 'edit', 'delete', 'enable', 'disable', 'comment'];

    public static function advertised(array $observed): bool
    {
        $api = $observed['neighbor_api'] ?? [];

        return is_array($observed['neighbors'] ?? null)
            && ($api['schema_version'] ?? null) === 1
            && ($api['observation'] ?? false) === true
            && ($api['disable_semantics'] ?? null) === 'remove-static-pin-allow-dynamic'
            && ($api['persistence'] ?? null) === 'uci-network'
            && ($api['verification'] ?? null) === 'kernel-and-metadata'
            && array_diff(self::OPERATIONS, $api['operations'] ?? []) === []
            && data_get($observed, 'neighbor_verification.ok') === true;
    }

    public static function normalize(array $entries): array
    {
        return array_map(fn (array $entry): array => [
            'ip' => $entry['ip'], 'mac' => strtolower($entry['mac']),
            'interface' => $entry['interface'], 'disabled' => (bool) ($entry['disabled'] ?? false),
            'comment' => (string) ($entry['comment'] ?? ''),
        ], $entries);
    }

    public static function verifiesConfiguration(array $entries, array $observed, array $previous = []): bool
    {
        if ($entries === [] && $previous === []) {
            return true;
        }
        if (! self::advertised($observed) || self::normalize($entries) !== self::normalize($observed['managed_neighbors'] ?? [])) {
            return false;
        }
        foreach ([...$entries, ...$previous] as $entry) {
            $target = collect($entries)->first(fn (array $item): bool => $item['ip'] === $entry['ip'] && $item['interface'] === $entry['interface']);
            $permanent = collect($observed['neighbors'])->filter(fn (array $actual): bool => ($actual['dst'] ?? '') === $entry['ip']
                && ($actual['dev'] ?? '') === $entry['interface'] && in_array('PERMANENT', $actual['state'] ?? [], true));
            if ($target && ! ($target['disabled'] ?? false)) {
                if (! $permanent->contains(fn (array $actual): bool => strtolower($actual['lladdr'] ?? '') === strtolower($target['mac']))) {
                    return false;
                }
            } elseif ($permanent->isNotEmpty()) {
                return false;
            }
        }

        return true;
    }

    public static function proof(RouterNode $router, array $before, array $after, array $observed): ?array
    {
        if (! self::advertised($observed) || self::normalize($after) !== self::normalize($observed['managed_neighbors'] ?? [])) {
            return null;
        }
        $before = self::normalize($before);
        $after = self::normalize($after);
        $operations = ['observe'];
        foreach ($after as $index => $entry) {
            $old = collect($before)->first(fn (array $item): bool => $item['ip'] === $entry['ip'] && $item['interface'] === $entry['interface']);
            if ($old === null) {
                $operations[] = 'add';
            }
            $previous = $old ?? ($before[$index] ?? null);
            if ($previous && ($old !== null || ! collect($after)->contains(fn (array $item): bool => $item['ip'] === $previous['ip'] && $item['interface'] === $previous['interface'])) && array_intersect_key($previous, array_flip(['ip', 'mac', 'interface'])) !== array_intersect_key($entry, array_flip(['ip', 'mac', 'interface']))) {
                $operations[] = 'edit';
            }
            if ($old && (bool) ($old['disabled'] ?? false) !== (bool) ($entry['disabled'] ?? false)) {
                $operations[] = ($entry['disabled'] ?? false) ? 'disable' : 'enable';
            }
            if ($old && ($old['comment'] ?? '') !== ($entry['comment'] ?? '')) {
                $operations[] = 'comment';
            }
        }
        foreach ($before as $old) {
            if (! collect($after)->contains(fn (array $entry): bool => $entry['ip'] === $old['ip'] && $entry['interface'] === $old['interface'])) {
                $operations[] = 'delete';
            }
        }

        return [
            'version' => data_get($observed, 'health.product_version'),
            'boot_id' => data_get($observed, 'health.boot_id'),
            'pin_sha256' => hash('sha256', trim((string) $router->appliance_ssh_known_host)),
            'operations' => array_values(array_unique($operations)),
        ];
    }

    public static function qualification(RouterNode $router, array $observed): array
    {
        $operations = [];
        $reboot = false;
        $version = data_get($observed, 'health.product_version');
        $boot = data_get($observed, 'health.boot_id');
        $pin = hash('sha256', trim((string) $router->appliance_ssh_known_host));
        foreach ($router->revisions as $revision) {
            $proof = data_get($revision->diff_summary, 'neighbor_proof');
            if (! $revision->applied_at || ! $revision->confirmed_at || $revision->apply_status !== 'applied'
                || ! $proof || ($proof['version'] ?? null) !== $version || ($proof['pin_sha256'] ?? null) !== $pin) {
                continue;
            }
            $operations = array_merge($operations, $proof['operations'] ?? []);
            $reboot = $reboot || ($boot && ! empty($proof['boot_id']) && $proof['boot_id'] !== $boot);
        }
        $reboot = $reboot && self::normalize($router->applied_configuration['static_neighbors'] ?? []) === self::normalize($observed['managed_neighbors'] ?? []);

        return ['operations' => array_values(array_unique($operations)), 'reboot' => (bool) $reboot];
    }

    public static function ready(RouterNode $router, array $observed): bool
    {
        $qualification = self::qualification($router, $observed);

        return self::advertised($observed) && $qualification['reboot']
            && array_diff(self::OPERATIONS, $qualification['operations']) === [];
    }
}
