<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LicenseActivation;
use App\Models\LicenseActivationKey;
use App\Models\LicensePlan;
use App\Models\RouterNode;
use App\Models\User;
use App\Services\Licensing\LicenseCrypto;
use App\Services\Licensing\LicenseEnforcement;
use App\Services\Licensing\LicensePolicyMatrix;
use App\Services\Licensing\LicenseService;
use App\Services\RouterConfigurationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class LicensingTest extends TestCase
{
    use RefreshDatabase;

    private LicenseService $licenses;

    private LicenseCrypto $crypto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->licenses = $this->app->make(LicenseService::class);
        $this->crypto = $this->app->make(LicenseCrypto::class);
        $this->licenses->ensureDefaultPlans();
    }

    public function test_non_admin_cannot_access_licensing_admin(): void
    {
        $user = User::factory()->create(['role' => 'operator', 'is_admin' => false]);

        $this->actingAs($user)
            ->get(route('licensing.admin'))
            ->assertForbidden();
    }

    public function test_license_admin_can_issue_activation_key_hashed_and_audited_without_plaintext(): void
    {
        $admin = User::factory()->licenseAdmin()->create();
        $plan = LicensePlan::query()->where('code', 'standard')->firstOrFail();

        $response = $this->actingAs($admin)->post(route('licensing.keys.issue'), [
            'plan_code' => 'standard',
            'customer_label' => 'Acme ISP',
            'max_activations' => 1,
        ]);

        $response->assertRedirect(route('licensing.admin'));
        $plaintext = session('issued_activation_key');
        $this->assertIsString($plaintext);
        $this->assertStringStartsWith('NUR-', $plaintext);
        $this->assertGreaterThanOrEqual(80, strlen($plaintext));

        $key = LicenseActivationKey::query()->firstOrFail();
        $this->assertSame($this->crypto->hashActivationKey($plaintext), $key->key_hash);

        $audit = AuditLog::query()->where('action', 'license.key.issued')->latest('id')->first();
        $this->assertNotNull($audit);
        $metadata = $audit->metadata ?? [];
        $this->assertSame($key->key_prefix, $metadata['key_prefix'] ?? null);
        $this->assertArrayNotHasKey('plaintext', $metadata);
        $this->assertStringNotContainsString($plaintext, (string) $audit->summary);
        $this->assertSame($plan->code, $metadata['plan'] ?? null);
    }

    public function test_valid_online_activation_signs_complete_schema_payload(): void
    {
        $admin = User::factory()->admin()->create();
        $router = RouterNode::factory()->create(['namespace_name' => 'nur-lic-valid']);
        $issued = $this->licenses->issueActivationKey(
            LicensePlan::query()->where('code', 'trial')->firstOrFail(),
            $admin,
        );

        $result = $this->licenses->activateOnline(
            $issued['plaintext'],
            'device-alpha-001',
            $router,
            'fp-alpha',
            'nonce-valid-1',
            $admin,
        );

        $payload = $this->licenses->verifyEnvelope($result['envelope'], 'device-alpha-001');
        foreach (LicenseCrypto::REQUIRED_PAYLOAD_FIELDS as $field) {
            $this->assertArrayHasKey($field, $payload);
        }
        $this->assertSame(1, $payload['schema_version']);
        $this->assertSame('trial', $payload['plan']);
        $this->assertSame('device-alpha-001', $payload['device_id']);
        $this->assertSame($result['envelope']['key_id'], $payload['signing_key_id']);
        $this->assertSame(LicensePolicyMatrix::STATE_VALID, $router->fresh()->license_status);

        $this->actingAs($admin)
            ->get(route('licensing.status', $router))
            ->assertOk()
            ->assertSee('valid');
    }

    public function test_invalid_signature_and_unknown_key_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $issued = $this->licenses->issueActivationKey(
            LicensePlan::query()->where('code', 'standard')->firstOrFail(),
            $admin,
        );
        $result = $this->licenses->activateOnline(
            $issued['plaintext'],
            'device-sig-1',
            null,
            null,
            'nonce-sig-1',
            $admin,
        );

        $tampered = $result['envelope'];
        $tampered['payload']['plan'] = 'enterprise';

        try {
            $this->licenses->verifyEnvelope($tampered, 'device-sig-1');
            $this->fail('Expected signature failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('signature', strtolower($exception->getMessage()));
        }

        $unknown = $result['envelope'];
        $unknown['key_id'] = 'not-a-real-key';
        $unknown['payload']['signing_key_id'] = 'not-a-real-key';
        // Re-sign with foreign key under forged id should still fail unknown key lookup
        // after we put a valid signature for a key not in trust store:
        $foreign = $this->crypto->generateKeyPair();
        $forged = $this->crypto->sign($unknown['payload'], $foreign['private_key'], 'not-a-real-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown signing key id.');
        $this->licenses->verifyEnvelope($forged, 'device-sig-1');
    }

    public function test_wrong_device_license_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $issued = $this->licenses->issueActivationKey(
            LicensePlan::query()->where('code', 'standard')->firstOrFail(),
            $admin,
        );
        $result = $this->licenses->activateOnline(
            $issued['plaintext'],
            'device-bound-a',
            null,
            null,
            'nonce-wrong-dev',
            $admin,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('License payload is bound to a different device.');
        $this->licenses->verifyEnvelope($result['envelope'], 'device-bound-b');
    }

    public function test_expiry_grace_matrix_and_offline_within_grace(): void
    {
        $admin = User::factory()->admin()->create();
        $issued = $this->licenses->issueActivationKey(
            LicensePlan::query()->where('code', 'trial')->firstOrFail(),
            $admin,
        );
        $result = $this->licenses->activateOnline(
            $issued['plaintext'],
            'device-expiry-1',
            null,
            null,
            'nonce-expiry-1',
            $admin,
        );

        $activation = $result['activation']->fresh('license');
        $activation->license->forceFill([
            'expires_at' => Carbon::parse('2026-01-01 00:00:00'),
            'offline_grace_days' => 14,
        ])->save();
        $activation->forceFill([
            'grace_expires_at' => Carbon::parse('2026-01-15 00:00:00'),
            'last_status_refresh_at' => Carbon::parse('2025-12-01 00:00:00'),
        ])->save();

        $within = $this->licenses->evaluateStatus($activation->fresh('license'), Carbon::parse('2026-01-10'));
        $this->assertSame(LicensePolicyMatrix::STATE_EXPIRED_WITHIN_GRACE, $within['state']);
        $this->assertFalse($within['allow_entitlement_mutations']);
        $this->assertFalse($within['disconnect_subscribers']);
        $this->assertFalse($within['block_local_recovery']);
        $this->assertFalse($within['block_safe_mode']);

        $after = $this->licenses->evaluateStatus($activation->fresh('license'), Carbon::parse('2026-01-20'));
        $this->assertSame(LicensePolicyMatrix::STATE_EXPIRED_AFTER_GRACE, $after['state']);
        $this->assertFalse($after['allow_entitlement_mutations']);

        // Still calendar-valid, server unreachable within offline delay.
        $activation->license->forceFill([
            'expires_at' => Carbon::parse('2027-01-01 00:00:00'),
        ])->save();
        $activation->forceFill([
            'last_status_refresh_at' => Carbon::parse('2026-01-01 00:00:00'),
        ])->save();

        $offline = $this->licenses->evaluateStatus(
            $activation->fresh('license'),
            Carbon::parse('2026-01-10'),
            serverReachable: false,
        );
        $this->assertSame(LicensePolicyMatrix::STATE_OFFLINE_WITHIN_GRACE, $offline['state']);
        $this->assertTrue($offline['allow_entitlement_mutations']);
    }

    public function test_replayed_activation_request_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = LicensePlan::query()->where('code', 'professional')->firstOrFail();
        $first = $this->licenses->issueActivationKey($plan, $admin, maxActivations: 2);
        $second = $this->licenses->issueActivationKey($plan, $admin, maxActivations: 2);

        $this->licenses->activateOnline(
            $first['plaintext'],
            'device-replay-1',
            null,
            null,
            'shared-nonce-xyz',
            $admin,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Replayed activation request rejected.');
        $this->licenses->activateOnline(
            $second['plaintext'],
            'device-replay-2',
            null,
            null,
            'shared-nonce-xyz',
            $admin,
        );
    }

    public function test_device_transfer_and_seat_limit_are_atomic(): void
    {
        $admin = User::factory()->admin()->create();
        $router = RouterNode::factory()->create(['namespace_name' => 'nur-lic-xfer']);
        $issued = $this->licenses->issueActivationKey(
            LicensePlan::query()->where('code', 'standard')->firstOrFail(),
            $admin,
            maxActivations: 1,
        );

        $original = $this->licenses->activateOnline(
            $issued['plaintext'],
            'device-from',
            $router,
            null,
            'nonce-xfer-from',
            $admin,
        );

        $transferred = $this->licenses->transferDevice(
            $original['activation']->fresh('license.activationKey'),
            'device-to',
            $issued['plaintext'],
            $admin,
        );

        $this->assertSame('revoked', $original['activation']->fresh()->status);
        $this->assertSame('active', $transferred['activation']->status);
        $this->assertSame('device-to', $transferred['activation']->device_id);
        $this->assertSame(
            1,
            LicenseActivation::query()->where('status', 'active')->count(),
        );

        try {
            $this->licenses->verifyEnvelope($transferred['envelope'], 'device-from');
            $this->fail('Expected wrong-device rejection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('different device', $exception->getMessage());
        }
    }

    public function test_concurrent_seat_consumption_cannot_exceed_max_activations(): void
    {
        $admin = User::factory()->admin()->create();
        $issued = $this->licenses->issueActivationKey(
            LicensePlan::query()->where('code', 'standard')->firstOrFail(),
            $admin,
            maxActivations: 1,
        );

        $ok = 0;
        $denied = 0;

        foreach (['device-c1', 'device-c2'] as $index => $deviceId) {
            try {
                DB::transaction(function () use ($issued, $deviceId, $admin, $index): void {
                    $this->licenses->activateOnline(
                        $issued['plaintext'],
                        $deviceId,
                        null,
                        null,
                        'nonce-concurrent-'.$index,
                        $admin,
                    );
                });
                $ok++;
            } catch (RuntimeException) {
                $denied++;
            }
        }

        $this->assertSame(1, $ok);
        $this->assertSame(1, $denied);
        $this->assertSame(1, LicenseActivation::query()->where('status', 'active')->count());
    }

    public function test_licensing_server_outage_policy_does_not_tear_down_traffic(): void
    {
        $policy = $this->app->make(LicenseEnforcement::class)->serverOutagePolicy();

        $this->assertFalse($policy['disconnect_subscribers']);
        $this->assertFalse($policy['remove_firewall_rules']);
        $this->assertFalse($policy['block_local_recovery']);
        $this->assertFalse($policy['block_safe_mode']);
        $this->assertTrue($policy['use_cached_license']);
        $this->assertSame(14, $policy['max_offline_revocation_delay_days']);
    }

    public function test_key_revocation_differs_from_license_revocation_and_refresh_signs_revoke(): void
    {
        $admin = User::factory()->admin()->create();
        $router = RouterNode::factory()->create([
            'namespace_name' => 'nur-lic-revoke',
            'desired_configuration' => [
                'interfaces' => [],
                'addresses' => [],
                'routes' => [],
                'bridge_vlans' => [],
                'services' => [],
                'forwarding' => [],
                'firewall' => [],
            ],
        ]);
        $issued = $this->licenses->issueActivationKey(
            LicensePlan::query()->where('code', 'standard')->firstOrFail(),
            $admin,
        );
        $activated = $this->licenses->activateOnline(
            $issued['plaintext'],
            'device-revoke-1',
            $router,
            null,
            'nonce-revoke-1',
            $admin,
        );

        $this->licenses->revokeKey($issued['key'], 'key_compromised', $admin);
        $this->assertSame('active', $activated['activation']->fresh()->status);

        $this->licenses->revokeLicense($activated['activation']->license()->firstOrFail(), 'fraud', $admin);
        $this->assertSame('revoked', $activated['activation']->fresh()->status);

        $refreshed = $this->licenses->refreshStatus($activated['activation']->fresh(), $admin);
        $this->assertSame('revoked', $refreshed['envelope']['payload']['status']);
        $this->assertSame(LicensePolicyMatrix::STATE_REVOKED, $refreshed['status']['state']);

        $manager = $this->app->make(RouterConfigurationManager::class);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('License blocks this WinControl mutation');
        $manager->createRevision($router->fresh(), $admin);
    }

    public function test_compromised_signing_key_is_not_trusted_for_rotation(): void
    {
        $admin = User::factory()->admin()->create();
        $issued = $this->licenses->issueActivationKey(
            LicensePlan::query()->where('code', 'standard')->firstOrFail(),
            $admin,
        );
        $result = $this->licenses->activateOnline(
            $issued['plaintext'],
            'device-compromise-1',
            null,
            null,
            'nonce-compromise-1',
            $admin,
        );

        $keyId = (string) config('licensing.signing_key_id');
        $currentPublic = $this->licenses->verificationPublicKeysById()[$keyId];

        // Compromised keys must be rejected even if still presented in a key map (no "keep old licenses" trust).
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('compromised');
        $this->crypto->verifyAndDecode($result['envelope'], [$keyId => $currentPublic], [$currentPublic]);
    }

    public function test_compromised_key_is_removed_from_trusted_verification_set(): void
    {
        $keyId = (string) config('licensing.signing_key_id');
        $currentPublic = $this->licenses->verificationPublicKeysById()[$keyId];
        config([
            'licensing.compromised_public_keys' => [$currentPublic],
            'licensing.previous_public_keys' => [$currentPublic],
        ]);

        $this->assertArrayNotHasKey($keyId, $this->licenses->verificationPublicKeysById());
    }

    public function test_offline_activation_round_trip(): void
    {
        $admin = User::factory()->admin()->create();
        $issued = $this->licenses->issueActivationKey(
            LicensePlan::query()->where('code', 'enterprise')->firstOrFail(),
            $admin,
        );
        $request = $this->licenses->buildOfflineRequest('device-offline-9', 'fp-9');

        $result = $this->licenses->activateOffline($issued['plaintext'], $request, $admin);
        $payload = $this->licenses->verifyEnvelope($result['envelope'], 'device-offline-9');

        $this->assertSame('enterprise', $payload['plan']);
        $this->assertSame($request['nonce'], $payload['nonce']);
        $this->assertNull($payload['expires_epoch']);
    }

    public function test_http_activation_shows_actionable_error_for_bad_key(): void
    {
        $admin = User::factory()->admin()->create();
        $router = RouterNode::factory()->create(['namespace_name' => 'nur-lic-http']);

        $this->actingAs($admin)
            ->post(route('licensing.activate', $router), [
                'activation_key' => 'NUR-AAAA-BBBB-CCCC-DDDD-EEEE-FFFF-0000-1111',
            ])
            ->assertRedirect(route('licensing.status', $router))
            ->assertSessionHas('license_error');
    }

    public function test_malformed_envelope_missing_required_field_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $issued = $this->licenses->issueActivationKey(
            LicensePlan::query()->where('code', 'standard')->firstOrFail(),
            $admin,
        );
        $result = $this->licenses->activateOnline(
            $issued['plaintext'],
            'device-malformed-1',
            null,
            null,
            'nonce-malformed-1',
            $admin,
        );

        unset($result['envelope']['payload']['activation_uuid']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing activation_uuid');
        $this->licenses->verifyEnvelope($result['envelope'], 'device-malformed-1');
    }
}
