<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DurableOperationProcessTest extends TestCase
{
    public function test_sigkill_after_remote_acceptance_preserves_owner_and_worker_reconciles_persisted_operation(): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('Requires POSIX process signals.');
        }
        $directory = sys_get_temp_dir().'/nur-operation-process-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database.sqlite', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        try {
            (new Process([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], base_path(), $environment))->mustRun();
            $bootstrap = '<?php require '.var_export(base_path('vendor/autoload.php'), true).'; $app = require '.var_export(base_path('bootstrap/app.php'), true).'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();';
            $worker = $bootstrap.<<<'CODE'
$router = App\Models\RouterNode::create(['name'=>'Crash fixture','namespace_name'=>'crash-fixture','management_interface'=>'eth0','management_cidr'=>'192.0.2.2/24','appliance_host'=>'127.0.0.1','appliance_ssh_user'=>'fixture','appliance_ssh_private_key'=>'fixture','appliance_ssh_known_host'=>'fixture','desired_configuration'=>[]]);
$app->instance(App\Services\OpenWrtApplianceClient::class, new class($app->make(App\Services\OpenWrtOverlayExporter::class)) extends App\Services\OpenWrtApplianceClient {
    public function applyConfiguration(App\Models\RouterNode $router, array $configuration, array $secrets, string $operationUuid, ?int $safeModeMinutes = null): array {
        file_put_contents(getenv('DB_DATABASE').'.accepted', $operationUuid);
        posix_kill(getmypid(), SIGKILL);
        exit(88);
    }
});
$manager = $app->make(App\Services\RouterConfigurationManager::class);
$manager->createRevision($router);
$manager->applyCurrentRevision($router->fresh());
CODE;
            file_put_contents($directory.'/worker.php', $worker);
            $process = new Process([PHP_BINARY, $directory.'/worker.php'], base_path(), $environment);
            try {
                $process->run();
            } catch (ProcessSignaledException $exception) {
                $this->assertSame(9, $process->getTermSignal());
            }
            $this->assertFalse($process->isSuccessful());
            $this->assertFileExists($environment['DB_DATABASE'].'.accepted');
            $owner = file_get_contents($environment['DB_DATABASE'].'.accepted');
            $database = new \PDO('sqlite:'.$environment['DB_DATABASE']);
            $this->assertSame($owner, $database->query('SELECT pending_operation_uuid FROM router_nodes')->fetchColumn());
            $database->exec("UPDATE router_nodes SET reconciliation_deadline_at='2000-01-01 00:00:00'");
            $reconciler = $bootstrap.<<<'CODE'
$app->instance(App\Services\OpenWrtApplianceClient::class, new class($app->make(App\Services\OpenWrtOverlayExporter::class)) extends App\Services\OpenWrtApplianceClient {
    public function observeConfiguration(App\Models\RouterNode $router): array {
        return ['apply_operations'=>[$router->pending_operation_uuid=>'confirmed']];
    }
});
exit($app->make(App\Services\RouterConfigurationManager::class)->reconcileExpiredOperations() === 1 ? 0 : 1);
CODE;
            file_put_contents($directory.'/reconcile.php', $reconciler);
            (new Process([PHP_BINARY, $directory.'/reconcile.php'], base_path(), $environment))->mustRun();
            $node = $database->query('SELECT * FROM router_nodes')->fetch(\PDO::FETCH_ASSOC);
            $this->assertNull($node['pending_operation_uuid']);
            $this->assertSame($node['desired_revision_id'], $node['applied_revision_id']);
            $this->assertSame('confirmed', $node['safe_mode_status']);
            $database = null;
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
