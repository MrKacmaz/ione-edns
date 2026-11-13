<?php

namespace App\Console\Commands;

use App\Events\MetricsUpdated;
use App\Services\Observability\MetricsCollector;
use Illuminate\Console\Command;

/**
 * Pushes a fresh metrics snapshot to the operator dashboard.
 *
 * Scheduled every few seconds, so open dashboards stay current without polling.
 */
class BroadcastMetricsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'metrics:broadcast';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Broadcast a fresh metrics snapshot to the operator dashboard';

    /**
     * Execute the console command.
     */
    public function handle(MetricsCollector $collector): int
    {
        MetricsUpdated::dispatch($collector->collect());

        return self::SUCCESS;
    }
}
