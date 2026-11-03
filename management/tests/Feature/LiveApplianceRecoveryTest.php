<?php

namespace Tests\Feature;

use App\Models\LicensePlan;
use App\Models\RouterNode;
use App\Services\Licensing\LicenseCrypto;
use App\Services\Licensing\LicenseService;
use App\Services\OpenWrtApplianceClient;
use App\Services\RouterConfigurationManager;
use App\Services\UciSerializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Opt-in acceptance against only the disposable loopback fixture, never inventory devices. */
class LiveApplianceRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function console(string $command): string
    {
        $process = new Process(['python3', '/var/www/nurrouter.com/nurrouteros/lab/qemu-console.py', '/tmp/nur-wincontrol-fix-lab/console.sock', $command, '25']);
        $process->setTimeout(40)->mustRun();

        return $process->getOutput();
    }

    private function installLicense(array $envelope, string $publicKey): void
    {
        $token = 'nurrouter-overlay-'.bin2hex(random_bytes(12));
        $directory = '/tmp/nur-wincontrol-fix-lab/'.$token;
        mkdir($directory, 0700);
        file_put_contents($directory.'/keys.json', json_encode(['keys' => [['key_id' => 'nur-lic-test', 'public_key' => $publicKey]]]));
        file_put_contents($directory.'/license.json', json_encode($envelope));
        try {
            $process = new Process(['scp', '-O', '-r', '-i', '/tmp/nur-wincontrol-fix-lab/id_ed25519', '-P', '2298', '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=/tmp/nur-wincontrol-fix-lab/known_hosts', $directory, 'nur-mgmt@127.0.0.1:/tmp/'.$token]);
            $process->setTimeout(30)->mustRun();
            $this->console("cp /tmp/{$token}/keys.json /etc/nurrouter/license-public-keys.json; cp /tmp/{$token}/license.json /etc/nurrouter/license.json; test -s /etc/nurrouter/license.json");
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_generated_configuration_and_recovery_under_restricted_licenses_on_immutable_dev8(): void
    {
        if (getenv('NUR_RUN_LIVE_FIX_TESTS') !== '1') {
            $this->markTestSkipped('Requires the explicitly provisioned disposable loopback dev.8 fixture.');
        }
        $lab = '/tmp/nur-wincontrol-fix-lab';
        $router = RouterNode::factory()->create([
            'management_interface' => 'eth0', 'management_cidr' => '192.0.2.2/24',
            'appliance_host' => '127.0.0.1', 'appliance_ssh_port' => 2298, 'appliance_ssh_user' => 'nur-mgmt',
            'appliance_ssh_private_key' => file_get_contents($lab.'/id_ed25519'),
            'appliance_ssh_known_host' => file_get_contents($lab.'/known_hosts'), 'desired_configuration' => [],
        ]);
        $this->console('rm -f /etc/nurrouter/license.json; for pending in /etc/nurrouter/runtime/pending/*.env; do [ ! -f "$pending" ] || nur-runtimectl expire --operation "$(basename "$pending" .env)" >/dev/null; done');
        $client = app(OpenWrtApplianceClient::class);
        $initial = $client->observeConfiguration($router);
        $this->assertSame('0.5.0-dev.8', $initial['health']['product_version']);
        $manager = app(RouterConfigurationManager::class);
        $configuration = [
            'interfaces' => [['name' => 'eth1', 'type' => 'ether']],
            'addresses' => [['interface' => 'eth1', 'cidr' => '198.51.100.1/24'], ['interface' => 'eth1', 'cidr' => '203.0.113.1/24']],
            'services' => ['dns' => ['search_domains' => ['one.example', 'two.example']]], 'forwarding' => ['ipv4' => true],
            'firewall' => ['rules' => [['chain' => 'input', 'action' => 'accept', 'protocol' => 'icmp'], ['chain' => 'output', 'action' => 'accept', 'protocol' => 'icmp'], ['chain' => 'forward', 'action' => 'drop', 'protocol' => 'icmp']]],
        ];
        $manager->updateDesiredState($router, $configuration);
        $manager->applyCurrentRevision($router->fresh());
        $observed = $client->observeConfiguration($router);
        $addresses = collect($observed['addresses'])->where('ifname', 'eth1')->first()['addr_info'];
        $this->assertContains('198.51.100.1', array_column($addresses, 'local'));
        $this->assertContains('203.0.113.1', array_column($addresses, 'local'));
        $this->assertSame('1', $observed['forwarding']['ipv4']);
        $chains = [];
        foreach ($observed['firewall']['nftables'] ?? [] as $entry) {
            if (str_contains($entry['rule']['comment'] ?? '', 'nur-')) {
                $chains[] = $entry['rule']['chain'];
            }
        }
        foreach (['input', 'output', 'forward'] as $chain) {
            $this->assertContains($chain, $chains);
        }

        $this->assertStringContainsString('119,one.example,two.example', $this->console('uci get dhcp.main.dhcp_option; grep -h dhcp-option=119 /var/etc/dnsmasq.conf.* || true'));
        $this->assertStringContainsString('one.example', $this->console('grep -h dhcp-option=119 /var/etc/dnsmasq.conf.*'));
        // Verify quoting with the appliance's actual UCI parser, without starting a PPPoE client.
        $uci = base64_encode(UciSerializer::section('interface', 'quoted')."\n".UciSerializer::entry('option', 'username', "O'Reilly\\router")."\n");
        $roundTrip = $this->console("mkdir -p /tmp/fix-uci; printf %s {$uci} | openssl base64 -d -A > /tmp/fix-uci/network; uci -c /tmp/fix-uci get network.quoted.username");
        $this->assertStringContainsString("O'Reilly\\router", $roundTrip);
        $crypto = app(LicenseCrypto::class);
        $licenses = app(LicenseService::class);
        $licenses->ensureDefaultPlans();
        $public = $licenses->verificationPublicKeysById()['nur-lic-test'];
        foreach (['expired', 'revoked', 'unverified'] as $state) {
            $this->console('rm -f /etc/nurrouter/license.json');
            $router->refresh()->update(['active_license_activation_id' => null]);
            $baseline = $router->fresh()->applied_revision_id;
            $configuration['routes'] = [['destination' => '203.0.114.0/24', 'device' => 'eth0', 'gateway' => '192.0.2.254', 'metric' => count($router->revisions()->get())]];
            $manager->updateDesiredState($router->fresh(), $configuration);
            $manager->applyCurrentRevision($router->fresh(), safeModeMinutes: 1);
            $key = $licenses->issueActivationKey(LicensePlan::where('code', 'trial')->firstOrFail());
            $activation = $licenses->activateOnline($key['plaintext'], $initial['health']['device_id'], $router->fresh(), allowRenew: true);
            $envelope = $activation['envelope'];
            if ($state === 'revoked') {
                $licenses->revokeActivation($activation['activation'], 'isolated recovery test');
                $envelope = $licenses->refreshStatus($activation['activation']->fresh())['envelope'];
            } elseif ($state === 'expired') {
                $activation['activation']->license->update(['expires_at' => now()->subDays(30)]);
                $payload = $envelope['payload'];
                $payload['issued_at'] = now()->subDays(31)->toIso8601String();
                $payload['issued_epoch'] = now()->subDays(31)->timestamp;
                $payload['expires_at'] = now()->subDays(30)->toIso8601String();
                $payload['expires_epoch'] = now()->subDays(30)->timestamp;
                $envelope = $crypto->sign($payload, config('licensing.signing_private_key'), 'nur-lic-test');
            } else {
                $envelope['signature'] = base64_encode(str_repeat('x', 64));
            }
            $this->installLicense($envelope, $public);
            $policy = $this->console('nur-license-check || true');
            $this->assertStringContainsString('"allow_entitlement_mutations":false', $policy);
            if ($state === 'revoked') {
                // Confirmation itself is also a license-independent recovery operation.
                $manager->confirmSafeMode($router->fresh());
                $this->assertSame('confirmed', $router->fresh()->safe_mode_status);
                // Re-arm an unlicensed operation, then revoke again to exercise local rollback.
                $this->console('rm -f /etc/nurrouter/license.json');
                $router->refresh()->update(['active_license_activation_id' => null]);
                $baseline = $router->fresh()->applied_revision_id;
                $configuration['routes'][0]['metric']++;
                $manager->updateDesiredState($router->fresh(), $configuration);
                $manager->applyCurrentRevision($router->fresh(), safeModeMinutes: 1);
                $this->installLicense($envelope, $public);
            }
            $operation = $router->fresh()->appliedRevision->operation_uuid;
            // No Laravel requests, commands, or SSH session drives rollback during this interval.
            $wait = new Process(['sleep', '70']);
            $wait->setTimeout(80)->mustRun();
            $after = $client->observeConfiguration($router);
            $this->assertSame('rolled_back', $after['apply_operations'][$operation]);
            $manager->recoverConfirmedConfiguration($router->fresh());
            $this->assertSame($baseline, $router->fresh()->applied_revision_id);
        }
        $this->console('rm -f /etc/nurrouter/license.json');
    }
}
