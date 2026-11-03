<?php

/**
 * Capability registry: maps license entitlement codes to product enforcement.
 * Only capabilities listed as supported are advertised as enforceable.
 */
return [
    'features' => [
        'routing' => [
            'supported' => true,
            'label' => 'Routing / interfaces / addresses / routes',
            'server_gate' => 'desired_state.routing',
            'appliance_gate' => null,
            'ui' => 'routers.configure + workspace network windows',
            'limitation' => 'Always required for appliance operation; unlicensed bring-up remains allowed.',
        ],
        'firewall' => [
            'supported' => true,
            'label' => 'Firewall and NAT rules',
            'server_gate' => 'desired_state.firewall',
            'appliance_gate' => null,
            'ui' => 'routers.configure firewall/NAT',
            'limitation' => null,
        ],
        'dhcp' => [
            'supported' => true,
            'label' => 'DHCP servers',
            'server_gate' => 'desired_state.dhcp',
            'appliance_gate' => null,
            'ui' => 'routers.configure DHCP + workspace leases',
            'limitation' => null,
        ],
        'dns' => [
            'supported' => true,
            'label' => 'DNS forwarders / search domains',
            'server_gate' => 'desired_state.dns',
            'appliance_gate' => null,
            'ui' => 'routers.configure DNS',
            'limitation' => null,
        ],
        'pppoe' => [
            'supported' => true,
            'label' => 'PPPoE client profiles',
            'server_gate' => 'desired_state.pppoe',
            'appliance_gate' => 'pppoe_session_admission',
            'ui' => 'routers.configure PPPoE + workspace sessions',
            'limitation' => 'Current image uses OpenWrt PPPoE clients (WAN), not a BRAS/pppoe-server concentrator.',
        ],
        'shaping' => [
            'supported' => true,
            'label' => 'PPPoE upload/download HTB shaping',
            'server_gate' => 'desired_state.shaping',
            'appliance_gate' => 'nur-pppoe-shape',
            'ui' => 'routers.configure PPPoE rate fields',
            'limitation' => 'Requires tc-full/kmod-sched/kmod-ifb on the appliance image.',
        ],
        'ha_export' => [
            'supported' => false,
            'label' => 'HA configuration export',
            'server_gate' => null,
            'appliance_gate' => null,
            'ui' => null,
            'limitation' => 'Not implemented in 0.4.0 RC — do not advertise as available.',
        ],
        'priority_support' => [
            'supported' => false,
            'label' => 'Priority support entitlement',
            'server_gate' => null,
            'appliance_gate' => null,
            'ui' => null,
            'limitation' => 'Commercial/process entitlement only; not a runtime feature gate.',
        ],
    ],

    'limits' => [
        'max_pppoe_sessions' => [
            'supported' => true,
            'label' => 'Maximum concurrent PPPoE client sessions',
            'governs' => 'admission_of_new_sessions',
            'server_gate' => 'desired_state.pppoe_count',
            'appliance_gate' => 'pppoe_session_admission',
            'zero_means' => 'unlimited',
            'on_expiry_or_downgrade' => 'preserve_established_sessions',
            'reconnect_policy' => 'Enforced as desired-state PPPoE client profile count vs max_pppoe_sessions at WinControl stage and appliance apply (before /etc/config mutation). rc.5 supports WAN clients only. The separate experimental 0.5.0 BRAS milestone also verifies signed limits for new live subscriber admission; see docs/BRAS-MILESTONE.md.',
        ],
        'max_routers' => [
            'supported' => true,
            'label' => 'Maximum managed routers in this installation inventory',
            'governs' => 'WinControl inventory admission; separate from activation_key.max_activations seats',
            'server_gate' => 'router_create',
            'appliance_gate' => null,
            'zero_means' => 'unlimited',
            'limitation' => 'Enforced when creating routers against the smallest finite max_routers limit of bound active plans in this installation. Zero is unlimited; organization-scoped inventories are not modeled.',
        ],
    ],
];
