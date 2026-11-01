<?php

namespace App\Console\Commands;

use App\Services\Licensing\LicenseCrypto;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('nur:license-generate-signing-key {--key-id=nur-lic-1} {--write-public-overlay}')]
#[Description('Generate Ed25519 licensing keypair; print private key for server env and optionally write appliance public keys')]
class GenerateLicenseSigningKeyCommand extends Command
{
    public function handle(LicenseCrypto $crypto): int
    {
        $pair = $crypto->generateKeyPair();
        $keyId = (string) $this->option('key-id');

        $this->components->info('Store the private key only on the licensing server (NUR_LICENSE_SIGNING_PRIVATE_KEY).');
        $this->line('NUR_LICENSE_SIGNING_KEY_ID='.$keyId);
        $this->line('NUR_LICENSE_SIGNING_PRIVATE_KEY='.$pair['private_key']);
        $this->newLine();
        $this->components->info('Public key for appliances / rotation:');
        $this->line($pair['public_key']);

        if ($this->option('write-public-overlay')) {
            $path = base_path('../files/etc/nurrouter/license-public-keys.json');
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode([
                'keys' => [[
                    'key_id' => $keyId,
                    'public_key' => $pair['public_key'],
                    'algorithm' => 'ed25519',
                ]],
                'updated_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->components->info("Wrote appliance public key overlay to {$path}");
        }

        return self::SUCCESS;
    }
}
