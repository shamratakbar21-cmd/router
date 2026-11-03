<?php

namespace App\Services;

use App\Http\Controllers\RouterBrasController;
use App\Http\Controllers\RouterDesktopMutationController;
use App\Http\Requests\UpdateRouterDesiredStateRequest;
use App\Models\RouterNode;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RouterConfigurationValidator
{
    /** Imports share the edit validators and retain supported desktop extensions. */
    public function validate(array $configuration, RouterNode $router, array $secrets = []): array
    {
        $allowed = ['namespace', 'management', 'interfaces', 'addresses', 'routes', 'bridge_vlans', 'static_neighbors', 'forwarding', 'firewall', 'services'];
        if (array_diff(array_keys($configuration), $allowed)) {
            throw ValidationException::withMessages(['configuration' => 'Unsupported configuration fields.']);
        }
        foreach (['interfaces', 'addresses', 'routes', 'bridge_vlans', 'static_neighbors', 'forwarding', 'firewall', 'services'] as $field) {
            if (isset($configuration[$field]) && ! is_array($configuration[$field])) {
                throw ValidationException::withMessages([$field => 'Configuration collections must be arrays.']);
            }
        }
        foreach (['interfaces', 'addresses', 'routes', 'bridge_vlans', 'static_neighbors', 'services.dhcp_servers', 'services.pppoe_clients', 'firewall.rules', 'firewall.nat'] as $path) {
            $rows = data_get($configuration, $path, []);
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 10000) {
                throw ValidationException::withMessages([$path => 'Use a bounded list of configuration entries.']);
            }
            foreach ($rows as $entry) {
                if (! is_array($entry) || $entry === []) {
                    throw ValidationException::withMessages([$path => 'Invalid configuration entry.']);
                }
            }
        }
        $services = $configuration['services'] ?? [];
        foreach ($services['pppoe_clients'] ?? [] as $profile) {
            if (is_array($profile) && array_key_exists('password', $profile)) {
                throw ValidationException::withMessages(['services.pppoe_clients' => 'Credentials must be carried only in the authenticated secret payload.']);
            }
        }
        foreach ($services['pppoe_server']['subscribers'] ?? [] as $subscriber) {
            if (is_array($subscriber) && array_key_exists('password', $subscriber)) {
                throw ValidationException::withMessages(['services.pppoe_server' => 'Subscriber credentials must be carried only in the authenticated secret payload.']);
            }
        }
        if (isset($services['pppoe_server']['radius']['secret'])) {
            throw ValidationException::withMessages(['services.pppoe_server' => 'RADIUS credentials must be carried only in the authenticated secret payload.']);
        }

        if (array_diff(array_keys($services), ['dns', 'dhcp_servers', 'pppoe_clients', 'pppoe_server', 'dhcp_reservations', 'ipv6', 'wireguard'])) {
            throw ValidationException::withMessages(['services' => 'Unsupported service configuration.']);
        }
        // The normal form and import use exactly the same base network validation.
        UpdateRouterDesiredStateRequest::validateConfiguration($configuration);
        $working = clone $router;
        $working->desired_configuration = $configuration;
        $editor = app(RouterDesktopMutationController::class);
        foreach (['static_neighbors' => 'arp', 'services.dhcp_reservations' => 'reservations', 'services.ipv6.addresses' => 'ipv6_addresses', 'services.ipv6.routes' => 'ipv6_routes', 'services.wireguard.interfaces' => 'wg_interfaces', 'services.wireguard.peers' => 'wg_peers'] as $path => $module) {
            $rows = data_get($configuration, $path, []);
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw ValidationException::withMessages([$path => 'Use a list of configuration entries.']);
            }
            foreach ($rows as $entry) {
                if (! is_array($entry)) {
                    throw ValidationException::withMessages([$path => 'Invalid configuration entry.']);
                }
                $editor->entry($module, $entry, $working);
            }
        }
        if (! empty($services['pppoe_server'])) {
            Validator::make($services['pppoe_server'], app(RouterBrasController::class)->validationRules())->validate();
            if (in_array($router->management_interface, $services['pppoe_server']['access_interfaces'] ?? [], true)) {
                throw ValidationException::withMessages(['services.pppoe_server' => 'Management interface cannot be a subscriber access interface.']);
            }
        }
        app(OpenWrtRuntimeConfigRenderer::class)->render($configuration, $secrets);

        return $configuration;
    }
}
