<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeploymentScriptsTest extends TestCase
{
    public function test_backup_and_rollback_restore_matching_code_database_and_storage(): void
    {
        $directory = sys_get_temp_dir().'/nur-deploy-test-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $root = $directory.'/app';
        mkdir($root);
        mkdir($root.'/database');
        mkdir($root.'/storage');
        mkdir($root.'/bootstrap');
        mkdir($root.'/bootstrap/cache');
        mkdir($directory.'/bin');
        foreach (['systemctl', 'php', 'health', 'nginx'] as $name) {
            file_put_contents($directory.'/bin/'.$name, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> \"\$DEPLOY_TEST_LOG\"\nexit 0\n");
            chmod($directory.'/bin/'.$name, 0700);
        }
        file_put_contents($directory.'/bin/sqlite3', "#!/usr/bin/python3\nimport sqlite3,sys,shlex\nsrc=sqlite3.connect(sys.argv[1]); dst=sqlite3.connect(shlex.split(sys.argv[2])[1]); src.backup(dst); dst.close(); src.close()\n");
        chmod($directory.'/bin/sqlite3', 0700);
        file_put_contents($directory.'/nginx.conf', 'fastcgi_read_timeout 60s;');
        $environment = ['NGINX_SITE' => $directory.'/nginx.conf', 'APP_ROOT' => $root, 'BACKUP_ROOT' => $directory.'/backups', 'APP_USER' => get_current_user(), 'HEALTH_COMMAND' => $directory.'/bin/health', 'DEPLOY_TEST_LOG' => $directory.'/calls', 'PATH' => $directory.'/bin:'.getenv('PATH')];
        try {
            file_put_contents($root.'/.env', 'APP_KEY=fixture-key');
            file_put_contents($root.'/artisan', '<?php');
            file_put_contents($root.'/version.txt', 'previous-code');
            file_put_contents($root.'/storage/persisted.txt', 'previous-storage');
            $database = new \PDO('sqlite:'.$root.'/database/database.sqlite');
            $database->exec('CREATE TABLE fixture (value TEXT)');
            $database->exec("INSERT INTO fixture VALUES ('previous-database')");
            $database = null;
            $backup = new Process(['bash', base_path('deploy/ubuntu/scripts/backup-wincontrol.sh')], env: $environment);
            $backup->mustRun();
            preg_match('/backup_ok (.+)/', $backup->getOutput(), $matches);
            $backupDirectory = trim($matches[1]);
            $this->assertSame(0700, fileperms($backupDirectory) & 0777);
            $this->assertFileExists($backupDirectory.'/code.tgz');
            file_put_contents($root.'/version.txt', 'new-incompatible-code');
            file_put_contents($directory.'/nginx.conf', 'fastcgi_read_timeout 660s;');
            file_put_contents($root.'/storage/persisted.txt', 'new-storage');
            $database = new \PDO('sqlite:'.$root.'/database/database.sqlite');
            $database->exec("UPDATE fixture SET value='new-database'");
            $database = null;
            $rollback = new Process(['bash', base_path('deploy/ubuntu/scripts/rollback.sh'), $backupDirectory], env: $environment);
            $rollback->mustRun();
            $this->assertSame('previous-code', file_get_contents($root.'/version.txt'));
            $this->assertSame('fastcgi_read_timeout 60s;', file_get_contents($directory.'/nginx.conf'));
            $this->assertSame('previous-storage', file_get_contents($root.'/storage/persisted.txt'));
            $database = new \PDO('sqlite:'.$root.'/database/database.sqlite');
            $this->assertSame('previous-database', $database->query('SELECT value FROM fixture')->fetchColumn());
            $this->assertStringContainsString('stop nur-wincontrol-queue.service', file_get_contents($directory.'/calls'));
            file_put_contents($root.'/.env', 'APP_KEY=wrong-key');
            $rollback->run();
            $this->assertFalse($rollback->isSuccessful());
            $this->assertStringContainsString('APP_KEY does not match', $rollback->getErrorOutput());
            $database = null;
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_installer_resolves_application_root_before_attempting_package_installation(): void
    {
        if (posix_geteuid() !== 0) {
            $this->markTestSkipped('Installer preflight requires root; package operations are intercepted.');
        }
        $directory = sys_get_temp_dir().'/nur-install-test-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        file_put_contents($directory.'/apt-get', "#!/bin/sh\necho INTERCEPTED_PACKAGE_INSTALL >&2\nexit 91\n");
        chmod($directory.'/apt-get', 0700);
        try {
            $process = new Process(['bash', base_path('deploy/ubuntu/install.sh')], env: ['SOURCE_DIR' => '', 'PATH' => $directory.':'.getenv('PATH')]);
            $process->run();
            $this->assertSame(91, $process->getExitCode());
            $this->assertStringContainsString('INTERCEPTED_PACKAGE_INSTALL', $process->getErrorOutput());
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
