<?php

namespace App\Services\Licensing;

use RuntimeException;
use SensitiveParameter;

/**
 * Ed25519 license payload signing/verification.
 * Private key material must never leave the licensing server or be embedded in appliances.
 */
class LicenseCrypto
{
    public const SCHEMA_VERSION = 1;

    /**
     * Required authenticated payload fields (schema v1).
     *
     * @var list<string>
     */
    public const REQUIRED_PAYLOAD_FIELDS = [
        'schema_version',
        'license_uuid',
        'activation_uuid',
        'plan',
        'device_id',
        'entitlements',
        'issued_at',
        'issued_epoch',
        'starts_at',
        'expires_at',
        'expires_epoch',
        'offline_grace_days',
        'status',
        'signing_key_id',
        'nonce',
    ];

    public function generateKeyPair(): array
    {
        $keypair = sodium_crypto_sign_keypair();

        return [
            'private_key' => sodium_bin2base64(
                sodium_crypto_sign_secretkey($keypair),
                SODIUM_BASE64_VARIANT_ORIGINAL,
            ),
            'public_key' => sodium_bin2base64(
                sodium_crypto_sign_publickey($keypair),
                SODIUM_BASE64_VARIANT_ORIGINAL,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{payload: array<string, mixed>, signature: string, key_id: string, schema_version: int}
     */
    public function sign(array $payload, #[SensitiveParameter] string $privateKeyBase64, string $keyId): array
    {
        $this->assertPayloadShape($payload);

        if ((string) ($payload['signing_key_id'] ?? '') !== $keyId) {
            throw new RuntimeException('Payload signing_key_id must match envelope key_id.');
        }

        $canonical = $this->canonicalJson($payload);
        $secretKey = sodium_base642bin($privateKeyBase64, SODIUM_BASE64_VARIANT_ORIGINAL);
        $signature = sodium_bin2base64(
            sodium_crypto_sign_detached($canonical, $secretKey),
            SODIUM_BASE64_VARIANT_ORIGINAL,
        );

        return [
            'schema_version' => (int) $payload['schema_version'],
            'payload' => $payload,
            'signature' => $signature,
            'key_id' => $keyId,
        ];
    }

    /**
     * @param  array{payload?: mixed, signature?: mixed, key_id?: mixed}  $envelope
     * @param  array<string, string>  $publicKeysById  key_id => base64 public key
     * @return array<string, mixed>
     */
    public function verifyAndDecode(array $envelope, array $publicKeysById, array $compromisedPublicKeys = []): array
    {
        if (! isset($envelope['payload'], $envelope['signature'], $envelope['key_id'])) {
            throw new RuntimeException('Malformed license envelope.');
        }

        $payload = $envelope['payload'];
        $signature = $envelope['signature'];
        $keyId = (string) $envelope['key_id'];

        if (! is_array($payload) || ! is_string($signature) || $signature === '' || $keyId === '') {
            throw new RuntimeException('Malformed license envelope.');
        }

        $this->assertPayloadShape($payload);

        if ((string) ($payload['signing_key_id'] ?? '') !== $keyId) {
            throw new RuntimeException('Envelope key_id does not match signed signing_key_id.');
        }

        if (! isset($publicKeysById[$keyId])) {
            throw new RuntimeException('Unknown signing key id.');
        }

        $publicKeyBase64 = $publicKeysById[$keyId];

        foreach ($compromisedPublicKeys as $compromised) {
            if (is_string($compromised) && $compromised !== '' && hash_equals($compromised, $publicKeyBase64)) {
                throw new RuntimeException('Signing key is marked compromised and is not trusted.');
            }
        }

        $canonical = $this->canonicalJson($payload);
        $signatureBinary = sodium_base642bin($signature, SODIUM_BASE64_VARIANT_ORIGINAL);
        $publicKey = sodium_base642bin($publicKeyBase64, SODIUM_BASE64_VARIANT_ORIGINAL);

        if (! sodium_crypto_sign_verify_detached($signatureBinary, $canonical, $publicKey)) {
            throw new RuntimeException('License signature verification failed.');
        }

        return $payload;
    }

    /**
     * @param  array{payload?: mixed, signature?: mixed, key_id?: mixed}  $envelope
     * @param  array<int|string, string>  $publicKeysBase64
     */
    public function verify(array $envelope, array $publicKeysBase64): bool
    {
        try {
            $byId = [];
            $keyId = (string) ($envelope['key_id'] ?? 'default');

            if (array_is_list($publicKeysBase64)) {
                foreach ($publicKeysBase64 as $index => $key) {
                    $byId[$index === 0 ? $keyId : 'key-'.$index] = $key;
                }
                // Prefer matching the envelope key against every listed key.
                foreach ($publicKeysBase64 as $key) {
                    $byId[$keyId] = $key;
                    try {
                        $this->verifyAndDecode($envelope, [$keyId => $key]);

                        return true;
                    } catch (RuntimeException) {
                        continue;
                    }
                }

                return false;
            }

            $this->verifyAndDecode($envelope, $publicKeysBase64);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * 256-bit activation keys (32 random bytes → 64 hex chars in NUR- groups).
     *
     * @return array{plaintext: string, prefix: string, hash: string}
     */
    public function generateActivationKey(): array
    {
        $raw = strtoupper(bin2hex(random_bytes(32)));
        $groups = str_split($raw, 4);
        $plaintext = 'NUR-'.implode('-', $groups);
        $prefix = substr($plaintext, 0, 15);

        return [
            'plaintext' => $plaintext,
            'prefix' => $prefix,
            'hash' => $this->hashActivationKey($plaintext),
        ];
    }

    public function hashActivationKey(#[SensitiveParameter] string $plaintext): string
    {
        return hash('sha256', strtoupper(trim($plaintext)));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function canonicalJson(array $payload): string
    {
        $encoded = json_encode($this->sortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw new RuntimeException('Unable to encode license payload.');
        }

        return $encoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertPayloadShape(array $payload): void
    {
        foreach (self::REQUIRED_PAYLOAD_FIELDS as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new RuntimeException("Malformed license payload: missing {$field}.");
            }
        }

        if ((int) $payload['schema_version'] !== self::SCHEMA_VERSION) {
            throw new RuntimeException('Unsupported license schema version.');
        }

        if (! is_string($payload['license_uuid']) || $payload['license_uuid'] === '') {
            throw new RuntimeException('Malformed license payload: license_uuid.');
        }

        if (! is_string($payload['activation_uuid']) || $payload['activation_uuid'] === '') {
            throw new RuntimeException('Malformed license payload: activation_uuid.');
        }

        if (! is_string($payload['plan']) || $payload['plan'] === '') {
            throw new RuntimeException('Malformed license payload: plan.');
        }

        if (! is_string($payload['device_id']) || $payload['device_id'] === '') {
            throw new RuntimeException('Malformed license payload: device_id.');
        }

        if (! is_array($payload['entitlements'])) {
            throw new RuntimeException('Malformed license payload: entitlements.');
        }

        if (! is_string($payload['issued_at']) || ! is_int($payload['issued_epoch'])) {
            throw new RuntimeException('Malformed license payload: issuance time.');
        }

        if (! is_string($payload['starts_at'])) {
            throw new RuntimeException('Malformed license payload: starts_at.');
        }

        if (! (is_string($payload['expires_at']) || $payload['expires_at'] === null)) {
            throw new RuntimeException('Malformed license payload: expires_at.');
        }

        if (! (is_int($payload['expires_epoch']) || $payload['expires_epoch'] === null)) {
            throw new RuntimeException('Malformed license payload: expires_epoch.');
        }

        if (! is_int($payload['offline_grace_days']) || $payload['offline_grace_days'] < 0) {
            throw new RuntimeException('Malformed license payload: offline_grace_days.');
        }

        if (! in_array($payload['status'], ['active', 'revoked'], true)) {
            throw new RuntimeException('Malformed license payload: status.');
        }

        if (! is_string($payload['signing_key_id']) || $payload['signing_key_id'] === '') {
            throw new RuntimeException('Malformed license payload: signing_key_id.');
        }

        if (! is_string($payload['nonce']) || $payload['nonce'] === '') {
            throw new RuntimeException('Malformed license payload: nonce.');
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        ksort($value);

        return $value;
    }
}
