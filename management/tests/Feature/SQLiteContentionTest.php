<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SQLiteContentionTest extends TestCase
{
    public function test_desktop_edit_retries_a_real_sqlite_read_to_write_collision_without_duplicate_revisions(): void
    {
        $directory = sys_get_temp_dir().'/nur-sqlite-contention-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database.sqlite', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync'];
        try {
            (new Process([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], base_path(), $environment))->mustRun();
            file_put_contents($directory.'/writer.php', <<<'CODE'
<?php
$db=new PDO('sqlite:'.getenv('DB_DATABASE'));$db->exec('PRAGMA busy_timeout=5000; BEGIN IMMEDIATE; UPDATE contention_probe SET value=1');file_put_contents(getenv('DB_DATABASE').'.writer-ready','ready');$db->exec('COMMIT');
CODE);
            $bootstrap = '<?php require '.var_export(base_path('vendor/autoload.php'), true).'; $app=require '.var_export(base_path('bootstrap/app.php'), true).'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();';
            file_put_contents($directory.'/edit.php', $bootstrap.<<<'CODE'
Illuminate\Support\Facades\DB::statement('CREATE TABLE contention_probe (value INTEGER)');Illuminate\Support\Facades\DB::table('contention_probe')->insert(['value'=>0]);
$user=App\Models\User::factory()->admin()->create();$router=App\Models\RouterNode::factory()->create(['desired_configuration'=>[]]);$manager=$app->make(App\Services\RouterConfigurationManager::class);$manager->createRevision($router);$router->refresh();Illuminate\Support\Facades\Auth::login($user);
$request=Illuminate\Http\Request::create('/fixture','POST',['module'=>'dns','action'=>'update','expected_revision_id'=>$router->desired_revision_id,'expected_lock_version'=>$router->configuration_lock_version,'entry'=>['forwarders'=>[],'search_domains'=>['contention.example']]]);$request->setUserResolver(fn()=>$user);
$writer=null;$started=false;
Illuminate\Support\Facades\DB::listen(function($query)use(&$writer,&$started){
 if(!$started&&Illuminate\Support\Facades\DB::transactionLevel()===1&&str_contains($query->sql,'select')&&str_contains($query->sql,'router_nodes')){
  $started=true;$writer=new Symfony\Component\Process\Process([PHP_BINARY,__DIR__.'/writer.php']);$writer->start();
  $deadline=microtime(true)+5;while(!file_exists(getenv('DB_DATABASE').'.writer-ready')){if(microtime(true)>$deadline)throw new RuntimeException('Concurrent writer did not acquire its lock');usleep(1000);}
 }
});
$response=$app->make(App\Http\Controllers\RouterDesktopMutationController::class)($request,$router,$manager,$app->make(App\Services\RouterStateProjector::class));
$writer->wait();$router->refresh();
if($response->getStatusCode()!==200||$writer->getExitCode()!==0||$router->revisions()->count()!==2||data_get($router->desired_configuration,'services.dns.search_domains.0')!=='contention.example'){fwrite(STDERR,$response->getContent());exit(1);}
echo json_encode(['status'=>'PASS','concurrent_writer_committed'=>true,'exactly_one_new_revision'=>true]);
CODE);
            $process = new Process([PHP_BINARY, $directory.'/edit.php'], base_path(), $environment);
            $process->setTimeout(20)->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertStringContainsString('"exactly_one_new_revision":true', $process->getOutput());
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
