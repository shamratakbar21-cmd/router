<?php

namespace App\Services\Licensing;

/**
 * Single permission matrix for license states.
 *
 * Forwarding, firewall, recovery, and Safe Mode are never torn down by licensing.
 * Only entitlement-gated mutations (new WinControl stage/apply) are restricted.
 */
final class LicensePolicyMatrix
{
    public const STATE_VALID = 'valid';

    public const STATE_OFFLINE_WITHIN_GRACE = 'offline_within_grace';

    public const STATE_EXPIRED_WITHIN_GRACE = 'expired_within_grace';

    public const STATE_EXPIRED_AFTER_GRACE = 'expired_after_grace';

    public const STATE_REVOKED = 'revoked';

    public const STATE_INVALID_SIGNATURE = 'invalid_signature';

    public const STATE_UNVERIFIED = 'unverified';

    public const STATE_WRONG_DEVICE = 'wrong_device';

    public const STATE_UNLICENSED = 'unlicensed';

    public const STATE_UNKNOWN_KEY = 'unknown_key';

    /**
     * @return array{
     *     allow_entitlement_mutations: bool,
     *     disconnect_subscribers: bool,
     *     remove_firewall_rules: bool,
     *     block_local_recovery: bool,
     *     block_safe_mode: bool,
     *     message: string
     * }
     */
    public static function permissions(string $state): array
    {
        $safe = [
            'disconnect_subscribers' => false,
            'remove_firewall_rules' => false,
            'block_local_recovery' => false,
            'block_safe_mode' => false,
        ];

        return match ($state) {
            self::STATE_VALID => $safe + [
                'allow_entitlement_mutations' => true,
                'message' => 'License valid. Entitlement-gated changes allowed.',
            ],
            self::STATE_OFFLINE_WITHIN_GRACE => $safe + [
                // License itself still valid; revocation may be delayed until refresh.
                'allow_entitlement_mutations' => true,
                'message' => 'Licensing server unreachable within offline grace. Cached verified license remains usable; refresh as soon as possible.',
            ],
            self::STATE_EXPIRED_WITHIN_GRACE => $safe + [
                'allow_entitlement_mutations' => false,
                'message' => 'License expired; still within grace. Existing traffic continues; new entitlement-gated changes are blocked.',
            ],
            self::STATE_EXPIRED_AFTER_GRACE => $safe + [
                'allow_entitlement_mutations' => false,
                'message' => 'License expired past grace. Existing traffic continues; new entitlement-gated changes are blocked.',
            ],
            self::STATE_REVOKED => $safe + [
                'allow_entitlement_mutations' => false,
                'message' => 'License revoked (authenticated). Existing traffic continues; new entitlement-gated changes are blocked.',
            ],
            self::STATE_INVALID_SIGNATURE, self::STATE_UNVERIFIED, self::STATE_UNKNOWN_KEY, self::STATE_WRONG_DEVICE => $safe + [
                'allow_entitlement_mutations' => false,
                'message' => 'License unverified or invalid. Existing traffic continues; new entitlement-gated changes are blocked.',
            ],
            self::STATE_UNLICENSED => $safe + [
                // Bring-up and recovery remain possible before first activation.
                'allow_entitlement_mutations' => true,
                'message' => 'No license bound. Recovery and initial administration remain available.',
            ],
            default => $safe + [
                'allow_entitlement_mutations' => false,
                'message' => 'Unknown license state; entitlement-gated changes are blocked.',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $permissions
     * @return array<string, mixed>
     */
    public static function statusPayload(string $state, array $extra = []): array
    {
        $permissions = self::permissions($state);

        return array_merge([
            'state' => $state,
            'enforce_new_features' => ! $permissions['allow_entitlement_mutations'],
            'allow_entitlement_mutations' => $permissions['allow_entitlement_mutations'],
            'disconnect_subscribers' => $permissions['disconnect_subscribers'],
            'remove_firewall_rules' => $permissions['remove_firewall_rules'],
            'block_local_recovery' => $permissions['block_local_recovery'],
            'block_safe_mode' => $permissions['block_safe_mode'],
            'message' => $permissions['message'],
        ], $extra);
    }
}
