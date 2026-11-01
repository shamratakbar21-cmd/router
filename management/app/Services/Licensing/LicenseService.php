<?php

namespace App\Services\Licensing;

use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\LicenseActivationKey;
use App\Models\LicensePlan;
use App\Models\LicenseRevocation;
use App\Models\LicenseTransfer;
use App\Models\RouterNode;
use App\Models\User;
use App\Services\AuditLogWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;
use Throwable;

class LicenseService
{
    public function __construct(
        private readonly LicenseCrypto $crypto,
        private readonly AuditLogWriter $auditLogWriter,
    ) {}

    public function ensureDefaultPlans(): void
    {
        foreach (config('licensing.default_plans', []) as $code => $plan) {
            LicensePlan::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $plan['name'],
                    'duration_days' => $plan['duration_days'],
                    'entitlements' => $plan['entitlements'],
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * @return array{key: LicenseActivationKey, plaintext: string}
     */
    public function issueActivationKey(LicensePlan $plan, ?User $actor = null, ?string $customerLabel = null, int $maxActivations = 1): array
    {
        $generated = $this->crypto->generateActivationKey();

        $key = LicenseActivationKey::query()->create([
            'license_plan_id' => $plan->id,
            'key_prefix' => $generated['prefix'],
            'key_hash' => $generated['hash'],
            'customer_label' => $customerLabel,
            'max_activations' => max(1, $maxActivations),
            'expires_at' => $plan->duration_days ? now()->addDays($plan->duration_days * 2) : null,
            'status' => 'available',
            'created_by' => $actor?->id,
        ]);

        $this->auditLogWriter->write(
            'license.key.issued',
            "Issued activation key prefix {$key->key_prefix} for plan {$plan->code}.",
            $key,
            $actor,
            metadata: [
                'plan' => $plan->code,
                'key_prefix' => $key->key_prefix,
            ],
        );

        return ['key' => $key, 'plaintext' => $generated['plaintext']];
    }

    /**
     * @return array{activation: LicenseActivation, envelope: array<string, mixed>}
     */
    public function activateOnline(
        #[SensitiveParameter] string $activationKeyPlaintext,
        string $deviceId,
        ?RouterNode $routerNode = null,
        ?string $deviceFingerprint = null,
        ?string $requestNonce = null,
        ?User $actor = null,
        bool $allowRenew = false,
    ): array {
        return $this->activate(
            $activationKeyPlaintext,
            $deviceId,
            'online',
            $routerNode,
            $deviceFingerprint,
            $requestNonce,
            $actor,
            $allowRenew,
        );
    }

    /**
     * @return array{activation: LicenseActivation, envelope: array<string, mixed>}
     */
    public function renewOnline(
        #[SensitiveParameter] string $activationKeyPlaintext,
        string $deviceId,
        ?RouterNode $routerNode = null,
        ?User $actor = null,
    ): array {
        return $this->activateOnline(
            $activationKeyPlaintext,
            $deviceId,
            $routerNode,
            null,
            (string) Str::uuid(),
            $actor,
            allowRenew: true,
        );
    }

    /**
     * @param  array{device_id: string, nonce: string, fingerprint?: string, renew?: bool}  $offlineRequest
     * @return array{activation: LicenseActivation, envelope: array<string, mixed>}
     */
    public function activateOffline(
        #[SensitiveParameter] string $activationKeyPlaintext,
        array $offlineRequest,
        ?User $actor = null,
    ): array {
        $deviceId = (string) ($offlineRequest['device_id'] ?? '');
        $nonce = (string) ($offlineRequest['nonce'] ?? '');

        if ($deviceId === '' || $nonce === '') {
            throw new RuntimeException('Offline activation request requires device_id and nonce.');
        }

        return $this->activate(
            $activationKeyPlaintext,
            $deviceId,
            'offline',
            null,
            isset($offlineRequest['fingerprint']) ? (string) $offlineRequest['fingerprint'] : null,
            $nonce,
            $actor,
            allowRenew: (bool) ($offlineRequest['renew'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildOfflineRequest(string $deviceId, ?string $deviceFingerprint = null): array
    {
        return [
            'device_id' => $deviceId,
            'fingerprint' => $deviceFingerprint,
            'nonce' => (string) Str::uuid(),
            'requested_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Authenticated status refresh: returns a newly signed envelope reflecting server truth
     * (including revocation). Appliances must replace local license.json with this envelope.
     *
     * @return array{activation: LicenseActivation, envelope: array<string, mixed>, status: array<string, mixed>}
     */
    public function refreshStatus(LicenseActivation $activation, ?User $actor = null): array
    {
        return DB::transaction(function () use ($activation, $actor): array {
            /** @var LicenseActivation $locked */
            $locked = LicenseActivation::query()->lockForUpdate()->findOrFail($activation->id);
            $license = License::query()->lockForUpdate()->findOrFail($locked->license_id);

            $locked->forceFill(['last_seen_at' => now(), 'last_status_refresh_at' => now()])->save();

            $status = $this->evaluateStatus($locked->fresh('license'), now(), serverReachable: true);
            $envelopeStatus = ($locked->status === 'revoked' || $license->isRevoked()) ? 'revoked' : 'active';

            $envelope = $this->signActivationEnvelope($locked, $license, $envelopeStatus);

            $encoded = json_encode($envelope, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new RuntimeException('Unable to encode signed license envelope.');
            }

            $locked->forceFill([
                'signed_payload' => $encoded,
                'payload_hash' => hash('sha256', $encoded),
            ])->save();

            if ($locked->router_node_id) {
                RouterNode::query()->whereKey($locked->router_node_id)->update([
                    'license_status' => $status['state'],
                    'license_checked_at' => now(),
                ]);
            }

            $this->auditLogWriter->write(
                'license.status.refreshed',
                "Refreshed license status for device {$locked->device_id} ({$status['state']}).",
                $locked,
                $actor,
                metadata: [
                    'device_id' => $locked->device_id,
                    'state' => $status['state'],
                    'envelope_status' => $envelopeStatus,
                ],
            );

            return [
                'activation' => $locked->fresh(),
                'envelope' => $envelope,
                'status' => $status,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyEnvelope(array $envelope, ?string $expectedDeviceId = null): array
    {
        $payload = $this->crypto->verifyAndDecode(
            $envelope,
            $this->verificationPublicKeysById(),
            config('licensing.compromised_public_keys', []),
        );

        if ($expectedDeviceId !== null && ! hash_equals($expectedDeviceId, (string) $payload['device_id'])) {
            throw new RuntimeException('License payload is bound to a different device.');
        }

        return $payload;
    }

    /**
     * Evaluate license state using the shared policy matrix.
     *
     * @return array<string, mixed>
     */
    public function evaluateStatus(
        LicenseActivation $activation,
        ?\DateTimeInterface $now = null,
        bool $serverReachable = true,
        ?\DateTimeInterface $lastAuthenticatedRefresh = null,
    ): array {
        $now = Carbon::parse($now ?? now());
        $license = $activation->license()->firstOrFail();
        $graceDays = (int) ($license->offline_grace_days ?: config('licensing.offline_grace_days_default', 14));
        $maxOfflineDelay = (int) config('licensing.max_offline_revocation_delay_days', $graceDays);

        if ($activation->revoked_at !== null || $activation->status === 'revoked' || $license->isRevoked()) {
            return LicensePolicyMatrix::statusPayload(LicensePolicyMatrix::STATE_REVOKED, [
                'expires_at' => $license->expires_at?->toIso8601String(),
                'grace_expires_at' => $activation->grace_expires_at?->toIso8601String(),
                'max_offline_revocation_delay_days' => $maxOfflineDelay,
            ]);
        }

        if ($license->isExpired($now)) {
            $graceUntil = $activation->grace_expires_at
                ?? ($license->expires_at?->copy()->addDays($graceDays));

            if ($graceUntil !== null && $now->lessThanOrEqualTo($graceUntil)) {
                return LicensePolicyMatrix::statusPayload(LicensePolicyMatrix::STATE_EXPIRED_WITHIN_GRACE, [
                    'expires_at' => $license->expires_at?->toIso8601String(),
                    'grace_expires_at' => $graceUntil->toIso8601String(),
                    'max_offline_revocation_delay_days' => $maxOfflineDelay,
                ]);
            }

            return LicensePolicyMatrix::statusPayload(LicensePolicyMatrix::STATE_EXPIRED_AFTER_GRACE, [
                'expires_at' => $license->expires_at?->toIso8601String(),
                'grace_expires_at' => $graceUntil?->toIso8601String(),
                'max_offline_revocation_delay_days' => $maxOfflineDelay,
            ]);
        }

        $refreshAt = Carbon::parse(
            $lastAuthenticatedRefresh
                ?? $activation->last_status_refresh_at
                ?? $activation->last_seen_at
                ?? $activation->activated_at
                ?? $now,
        );

        if (! $serverReachable) {
            $offlineDeadline = $refreshAt->copy()->addDays($maxOfflineDelay);

            if ($now->lessThanOrEqualTo($offlineDeadline)) {
                return LicensePolicyMatrix::statusPayload(LicensePolicyMatrix::STATE_OFFLINE_WITHIN_GRACE, [
                    'expires_at' => $license->expires_at?->toIso8601String(),
                    'grace_expires_at' => $offlineDeadline->toIso8601String(),
                    'last_status_refresh_at' => $refreshAt->toIso8601String(),
                    'max_offline_revocation_delay_days' => $maxOfflineDelay,
                    'entitlements' => $license->entitlements,
                ]);
            }

            // Past offline revocation delay without refresh: treat as restricted (revocation may have occurred).
            return LicensePolicyMatrix::statusPayload(LicensePolicyMatrix::STATE_EXPIRED_AFTER_GRACE, [
                'expires_at' => $license->expires_at?->toIso8601String(),
                'grace_expires_at' => $offlineDeadline->toIso8601String(),
                'last_status_refresh_at' => $refreshAt->toIso8601String(),
                'max_offline_revocation_delay_days' => $maxOfflineDelay,
                'message' => 'No authenticated status refresh within maximum offline revocation delay. Entitlement-gated changes blocked until refresh.',
            ]);
        }

        return LicensePolicyMatrix::statusPayload(LicensePolicyMatrix::STATE_VALID, [
            'expires_at' => $license->expires_at?->toIso8601String(),
            'grace_expires_at' => $activation->grace_expires_at?->toIso8601String(),
            'last_status_refresh_at' => $refreshAt->toIso8601String(),
            'max_offline_revocation_delay_days' => $maxOfflineDelay,
            'server_reachable' => true,
            'entitlements' => $license->entitlements,
        ]);
    }

    public function revokeActivation(LicenseActivation $activation, string $reason, ?User $actor = null): void
    {
        DB::transaction(function () use ($activation, $reason, $actor): void {
            /** @var LicenseActivation $locked */
            $locked = LicenseActivation::query()->lockForUpdate()->findOrFail($activation->id);

            $locked->forceFill([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revoke_reason' => $reason,
            ])->save();

            LicenseRevocation::query()->create([
                'license_id' => $locked->license_id,
                'license_activation_id' => $locked->id,
                'scope' => 'activation',
                'reason' => $reason,
                'revoked_by' => $actor?->id,
            ]);

            if ($locked->router_node_id) {
                RouterNode::query()->whereKey($locked->router_node_id)->update([
                    'license_status' => LicensePolicyMatrix::STATE_REVOKED,
                    'license_checked_at' => now(),
                ]);
            }
        });

        $this->auditLogWriter->write(
            'license.activation.revoked',
            "Revoked license activation for device {$activation->device_id}.",
            $activation,
            $actor,
            metadata: ['reason' => $reason, 'device_id' => $activation->device_id, 'scope' => 'activation'],
        );
    }

    /**
     * Revoke an already-issued license (all activations). Distinct from activation-key revocation.
     */
    public function revokeLicense(License $license, string $reason, ?User $actor = null): void
    {
        DB::transaction(function () use ($license, $reason, $actor): void {
            /** @var License $locked */
            $locked = License::query()->lockForUpdate()->findOrFail($license->id);
            $locked->forceFill(['status' => 'revoked'])->save();

            LicenseActivation::query()
                ->where('license_id', $locked->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get()
                ->each(function (LicenseActivation $activation) use ($reason): void {
                    $activation->forceFill([
                        'status' => 'revoked',
                        'revoked_at' => now(),
                        'revoke_reason' => $reason,
                    ])->save();

                    if ($activation->router_node_id) {
                        RouterNode::query()->whereKey($activation->router_node_id)->update([
                            'license_status' => LicensePolicyMatrix::STATE_REVOKED,
                            'license_checked_at' => now(),
                        ]);
                    }
                });

            LicenseRevocation::query()->create([
                'license_id' => $locked->id,
                'scope' => 'license',
                'reason' => $reason,
                'revoked_by' => $actor?->id,
            ]);
        });

        $this->auditLogWriter->write(
            'license.revoked',
            "Revoked issued license {$license->uuid}.",
            $license,
            $actor,
            metadata: ['reason' => $reason, 'scope' => 'license', 'license_uuid' => $license->uuid],
        );
    }

    /**
     * Revoke an activation key so it cannot create NEW activations.
     * Already-issued licenses remain active until explicitly revoked or refreshed as revoked.
     */
    public function revokeKey(LicenseActivationKey $key, string $reason, ?User $actor = null): void
    {
        DB::transaction(function () use ($key, $reason, $actor): void {
            /** @var LicenseActivationKey $locked */
            $locked = LicenseActivationKey::query()->lockForUpdate()->findOrFail($key->id);
            $locked->forceFill([
                'status' => 'revoked',
                'revoked_at' => now(),
            ])->save();

            LicenseRevocation::query()->create([
                'license_activation_key_id' => $locked->id,
                'scope' => 'activation_key',
                'reason' => $reason,
                'revoked_by' => $actor?->id,
                'metadata' => [
                    'note' => 'Key revocation blocks new activations only; issued licenses require license/activation revocation plus status refresh.',
                ],
            ]);
        });

        $this->auditLogWriter->write(
            'license.key.revoked',
            "Revoked activation key prefix {$key->key_prefix}.",
            $key,
            $actor,
            metadata: ['reason' => $reason, 'key_prefix' => $key->key_prefix, 'scope' => 'activation_key'],
        );
    }

    /**
     * @return array{activation: LicenseActivation, envelope: array<string, mixed>}
     */
    public function transferDevice(
        LicenseActivation $fromActivation,
        string $toDeviceId,
        #[SensitiveParameter] string $activationKeyPlaintext,
        ?User $actor = null,
    ): array {
        $toDeviceId = trim($toDeviceId);

        if ($toDeviceId === '') {
            throw new RuntimeException('to_device_id is required.');
        }

        return DB::transaction(function () use ($fromActivation, $toDeviceId, $activationKeyPlaintext, $actor): array {
            /** @var LicenseActivation $from */
            $from = LicenseActivation::query()->with('license.activationKey')->lockForUpdate()->findOrFail($fromActivation->id);

            if ($from->status !== 'active') {
                throw new RuntimeException('Only an active activation can be transferred.');
            }

            $key = $from->license?->activationKey;
            if ($key === null || ! hash_equals(
                $this->crypto->hashActivationKey($activationKeyPlaintext),
                (string) $key->key_hash,
            )) {
                throw new RuntimeException('Device transfer requires the original activation key.');
            }

            /** @var LicenseActivationKey $lockedKey */
            $lockedKey = LicenseActivationKey::query()->lockForUpdate()->findOrFail($key->id);

            $transfer = LicenseTransfer::query()->create([
                'license_id' => $from->license_id,
                'from_activation_id' => $from->id,
                'from_device_id' => $from->device_id,
                'to_device_id' => $toDeviceId,
                'status' => 'pending',
                'requested_by' => $actor?->id,
            ]);

            $from->forceFill([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revoke_reason' => 'device_transfer',
            ])->save();

            LicenseRevocation::query()->create([
                'license_id' => $from->license_id,
                'license_activation_id' => $from->id,
                'scope' => 'activation',
                'reason' => 'device_transfer',
                'revoked_by' => $actor?->id,
            ]);

            $lockedKey->forceFill([
                'status' => 'available',
            ])->save();

            $result = $this->activate(
                $activationKeyPlaintext,
                $toDeviceId,
                'online',
                $from->routerNode,
                null,
                (string) Str::uuid(),
                $actor,
                allowRenew: false,
                alreadyInTransaction: true,
            );

            $transfer->forceFill([
                'to_activation_id' => $result['activation']->id,
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            $this->auditLogWriter->write(
                'license.transfer.completed',
                "Transferred license from {$from->device_id} to {$toDeviceId}.",
                $result['activation'],
                $actor,
                metadata: [
                    'from_device_id' => $from->device_id,
                    'to_device_id' => $toDeviceId,
                ],
            );

            return $result;
        });
    }

    /**
     * @return array<string, string> key_id => public key
     */
    public function verificationPublicKeysById(): array
    {
        $keys = [];
        $currentId = (string) config('licensing.signing_key_id', 'nur-lic-1');
        $private = config('licensing.signing_private_key');

        if (is_string($private) && $private !== '') {
            $secret = sodium_base642bin($private, SODIUM_BASE64_VARIANT_ORIGINAL);
            if (strlen($secret) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                $keys[$currentId] = sodium_bin2base64(
                    sodium_crypto_sign_publickey_from_secretkey($secret),
                    SODIUM_BASE64_VARIANT_ORIGINAL,
                );
            }
        }

        $path = base_path('../files/etc/nurrouter/license-public-keys.json');
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            foreach (($decoded['keys'] ?? []) as $entry) {
                if (! empty($entry['key_id']) && ! empty($entry['public_key'])) {
                    $keys[(string) $entry['key_id']] = (string) $entry['public_key'];
                }
            }
        }

        // Routine rotation trust (never include compromised keys).
        $compromised = config('licensing.compromised_public_keys', []);
        foreach (config('licensing.previous_public_keys', []) as $index => $key) {
            if (in_array($key, $compromised, true)) {
                continue;
            }
            $keys['previous-'.$index] = $key;
        }

        foreach ($compromised as $key) {
            foreach ($keys as $id => $candidate) {
                if (hash_equals((string) $key, (string) $candidate)) {
                    unset($keys[$id]);
                }
            }
        }

        return $keys;
    }

    /**
     * @return array<int, string>
     */
    public function verificationPublicKeys(): array
    {
        return array_values($this->verificationPublicKeysById());
    }

    public function signingPrivateKey(): string
    {
        $key = config('licensing.signing_private_key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('NUR_LICENSE_SIGNING_PRIVATE_KEY is not configured on the licensing server.');
        }

        return $key;
    }

    /**
     * @return array{activation: LicenseActivation, envelope: array<string, mixed>}
     */
    private function activate(
        #[SensitiveParameter] string $activationKeyPlaintext,
        string $deviceId,
        string $mode,
        ?RouterNode $routerNode,
        ?string $deviceFingerprint,
        ?string $requestNonce,
        ?User $actor,
        bool $allowRenew = false,
        bool $alreadyInTransaction = false,
    ): array {
        $deviceId = trim($deviceId);
        $requestNonce = $requestNonce ?: (string) Str::uuid();

        if ($deviceId === '') {
            throw new RuntimeException('device_id is required.');
        }

        // Never treat client-supplied digests as proof of device ownership — only server-side device_id binding.
        $run = function () use (
            $activationKeyPlaintext,
            $deviceId,
            $mode,
            $routerNode,
            $deviceFingerprint,
            $requestNonce,
            $actor,
            $allowRenew,
        ): array {
            if (LicenseActivation::query()->where('request_nonce', $requestNonce)->lockForUpdate()->exists()) {
                throw new RuntimeException('Replayed activation request rejected.');
            }

            $hash = $this->crypto->hashActivationKey($activationKeyPlaintext);

            /** @var LicenseActivationKey|null $key */
            $key = LicenseActivationKey::query()->where('key_hash', $hash)->lockForUpdate()->first();

            if ($key === null || ! $key->isUsable()) {
                throw new RuntimeException('Activation key is invalid, expired, or revoked.');
            }

            $existingForDevice = LicenseActivation::query()
                ->where('device_id', $deviceId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($existingForDevice !== null) {
                if (! $allowRenew) {
                    throw new RuntimeException('Device already has an active license activation.');
                }

                $existingForDevice->forceFill([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                    'revoke_reason' => 'renewal_superseded',
                ])->save();

                LicenseRevocation::query()->create([
                    'license_id' => $existingForDevice->license_id,
                    'license_activation_id' => $existingForDevice->id,
                    'scope' => 'activation',
                    'reason' => 'renewal_superseded',
                    'revoked_by' => $actor?->id,
                ]);

                $key->refresh();
                if ($key->status === 'exhausted') {
                    $key->forceFill(['status' => 'partially_used'])->save();
                }
            }

            $activeCount = LicenseActivation::query()
                ->whereHas('license', fn ($q) => $q->where('license_activation_key_id', $key->id))
                ->where('status', 'active')
                ->lockForUpdate()
                ->count();

            if ($activeCount >= $key->max_activations) {
                throw new RuntimeException('Activation key has no remaining seats.');
            }

            $plan = $key->plan()->firstOrFail();
            $startsAt = now();
            $issuedAt = now();
            $expiresAt = $plan->duration_days ? $startsAt->copy()->addDays($plan->duration_days) : null;
            $graceDays = (int) config('licensing.offline_grace_days_default', 14);
            $signingKeyId = (string) config('licensing.signing_key_id', 'nur-lic-1');
            $licenseUuid = (string) Str::uuid();
            $activationUuid = (string) Str::uuid();

            $license = License::query()->create([
                'uuid' => $licenseUuid,
                'license_plan_id' => $plan->id,
                'license_activation_key_id' => $key->id,
                'customer_label' => $key->customer_label,
                'entitlements' => $plan->entitlements,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'offline_grace_days' => $graceDays,
                'status' => 'active',
                'signing_key_id' => $signingKeyId,
            ]);

            $activation = LicenseActivation::query()->create([
                'uuid' => $activationUuid,
                'license_id' => $license->id,
                'router_node_id' => $routerNode?->id,
                'device_id' => $deviceId,
                'device_fingerprint' => $deviceFingerprint,
                'request_nonce' => $requestNonce,
                'activation_mode' => $mode,
                'signed_payload' => '{}',
                'payload_hash' => hash('sha256', '{}'),
                'activated_at' => $issuedAt,
                'last_seen_at' => $issuedAt,
                'last_status_refresh_at' => $issuedAt,
                'grace_expires_at' => $expiresAt?->copy()->addDays($graceDays),
                'status' => 'active',
            ]);

            $envelope = $this->signActivationEnvelope($activation, $license, 'active', $requestNonce);

            $encoded = json_encode($envelope, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new RuntimeException('Unable to encode signed license envelope.');
            }

            $activation->forceFill([
                'signed_payload' => $encoded,
                'payload_hash' => hash('sha256', $encoded),
            ])->save();

            $used = LicenseActivation::query()
                ->whereHas('license', fn ($q) => $q->where('license_activation_key_id', $key->id))
                ->where('status', 'active')
                ->count();

            $key->forceFill([
                'status' => $used >= $key->max_activations ? 'exhausted' : 'partially_used',
            ])->save();

            if ($routerNode !== null) {
                $routerNode->forceFill([
                    'device_id' => $deviceId,
                    'active_license_activation_id' => $activation->id,
                    'license_status' => LicensePolicyMatrix::STATE_VALID,
                    'license_checked_at' => now(),
                ])->save();
            }

            $this->auditLogWriter->write(
                'license.activated',
                "Activated {$plan->code} license on device {$deviceId} ({$mode}).",
                $activation,
                $actor,
                metadata: [
                    'device_id' => $deviceId,
                    'mode' => $mode,
                    'plan' => $plan->code,
                    'key_prefix' => $key->key_prefix,
                    'license_uuid' => $licenseUuid,
                    'activation_uuid' => $activationUuid,
                ],
            );

            return [
                'activation' => $activation->fresh(),
                'envelope' => $envelope,
            ];
        };

        try {
            return $alreadyInTransaction ? $run() : DB::transaction($run);
        } catch (Throwable $throwable) {
            if (str_contains($throwable->getMessage(), 'UNIQUE constraint')
                || str_contains($throwable->getMessage(), 'Duplicate entry')
                || str_contains($throwable->getMessage(), 'unique')) {
                throw new RuntimeException('Activation conflict: duplicate nonce or device entitlement.', 0, $throwable);
            }

            throw $throwable;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function signActivationEnvelope(
        LicenseActivation $activation,
        License $license,
        string $status,
        ?string $nonce = null,
    ): array {
        $plan = $license->plan()->firstOrFail();
        $signingKeyId = (string) ($license->signing_key_id ?: config('licensing.signing_key_id', 'nur-lic-1'));
        $issuedAt = now();

        $payload = [
            'schema_version' => LicenseCrypto::SCHEMA_VERSION,
            'license_uuid' => $license->uuid,
            'activation_uuid' => $activation->uuid,
            'plan' => $plan->code,
            'device_id' => $activation->device_id,
            'entitlements' => $license->entitlements,
            'issued_at' => $issuedAt->toIso8601String(),
            'issued_epoch' => $issuedAt->getTimestamp(),
            'starts_at' => $license->starts_at->toIso8601String(),
            'expires_at' => $license->expires_at?->toIso8601String(),
            'expires_epoch' => $license->expires_at?->getTimestamp(),
            'offline_grace_days' => (int) $license->offline_grace_days,
            'status' => $status,
            'signing_key_id' => $signingKeyId,
            'nonce' => $nonce ?: (string) Str::uuid(),
        ];

        return $this->crypto->sign($payload, $this->signingPrivateKey(), $signingKeyId);
    }
}
