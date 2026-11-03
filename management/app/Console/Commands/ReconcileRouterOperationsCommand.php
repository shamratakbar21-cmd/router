<?php

namespace App\Console\Commands;

use App\Services\RouterConfigurationManager;
use Illuminate\Console\Command;

class ReconcileRouterOperationsCommand extends Command
{
    protected $signature = 'nur:reconcile-operations';

    protected $description = 'Reconcile durable router operations against appliance operation IDs';

    public function handle(RouterConfigurationManager $manager): int
    {
        $this->info('Reconciled '.$manager->reconcileExpiredOperations().' operations.');

        return self::SUCCESS;
    }
}
