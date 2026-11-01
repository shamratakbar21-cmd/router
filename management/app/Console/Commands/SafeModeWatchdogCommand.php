<?php

namespace App\Console\Commands;

use App\Services\RouterConfigurationManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nur:safe-mode-watchdog')]
#[Description('Rollback expired Safe Mode revisions')]
class SafeModeWatchdogCommand extends Command
{
    public function __construct(private readonly RouterConfigurationManager $configurationManager)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $rolledBackCount = $this->configurationManager->rollbackExpiredSafeModes();

        $this->components->info("Rolled back {$rolledBackCount} expired Safe Mode session(s).");

        return self::SUCCESS;
    }
}
