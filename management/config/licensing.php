<?php

return [
    /*
    | Signing material lives only on the licensing/control-plane host.
    | Appliances embed verification public keys under files/etc/nurrouter/license-public-keys.json.
    */
    'signing_private_key' => env('NUR_LICENSE_SIGNING_PRIVATE_KEY'),
    'signing_key_id' => env('NUR_LICENSE_SIGNING_KEY_ID', 'nur-lic-1'),

    /*
    | Routine rotation only: previous public keys remain trusted briefly so already-issued
    | envelopes verify during rollout. Compromised keys must NEVER be listed here — remove
    | them from appliance overlays immediately and re-issue licenses under a new key.
    */
    'previous_public_keys' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('NUR_LICENSE_PREVIOUS_PUBLIC_KEYS', '')),
    ))),

    'compromised_public_keys' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('NUR_LICENSE_COMPROMISED_PUBLIC_KEYS', '')),
    ))),

    'schema_version' => 1,

    /*
    | Offline grace covers two clocks:
    | 1) after calendar expiry (expired_within_grace → expired_after_grace)
    | 2) maximum time without an authenticated status refresh (offline revocation delay)
    */
    'offline_grace_days_default' => (int) env('NUR_LICENSE_OFFLINE_GRACE_DAYS', 14),
    'max_offline_revocation_delay_days' => (int) env('NUR_LICENSE_MAX_OFFLINE_REVOCATION_DELAY_DAYS', 14),
    'activation_nonce_ttl_hours' => (int) env('NUR_LICENSE_NONCE_TTL_HOURS', 24),
    'activation_rate_limit_per_minute' => (int) env('NUR_LICENSE_ACTIVATION_RATE_LIMIT', 10),

    'safe_enforcement' => [
        'block_new_feature_entitlements' => true,
        'disconnect_subscribers_on_expiry' => false,
        'remove_firewall_rules_on_expiry' => false,
        'block_local_recovery_on_expiry' => false,
        'block_wincontrol_mutations_when_restricted' => true,
    ],

    'default_plans' => [
        'trial' => [
            'name' => 'Trial',
            'duration_days' => 30,
            'entitlements' => [
                'max_routers' => 1,
                'max_pppoe_sessions' => 50,
                'features' => ['routing', 'firewall', 'dhcp', 'dns', 'pppoe'],
            ],
        ],
        'standard' => [
            'name' => 'Standard',
            'duration_days' => 365,
            'entitlements' => [
                'max_routers' => 5,
                'max_pppoe_sessions' => 250,
                'features' => ['routing', 'firewall', 'dhcp', 'dns', 'pppoe', 'shaping'],
            ],
        ],
        'professional' => [
            'name' => 'Professional',
            'duration_days' => 365,
            'entitlements' => [
                'max_routers' => 25,
                'max_pppoe_sessions' => 1000,
                // ha_export is not implemented in 0.4.0 RC — do not advertise.
                'features' => ['routing', 'firewall', 'dhcp', 'dns', 'pppoe', 'shaping'],
            ],
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'duration_days' => null,
            'entitlements' => [
                'max_routers' => 0,
                'max_pppoe_sessions' => 0,
                // priority_support / ha_export are non-runtime; omit from enforced feature list.
                'features' => ['routing', 'firewall', 'dhcp', 'dns', 'pppoe', 'shaping'],
            ],
        ],
    ],
];
