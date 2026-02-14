<?php

namespace App\Console\Commands;

use App\Services\ConnectorReliabilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RollupConnectorMetrics extends Command
{
    /**
     * @var string
     */
    protected $signature = 'connectors:rollup-metrics {--day= : YYYY-MM-DD date to roll up (default yesterday)}';

    /**
     * @var string
     */
    protected $description = 'Roll up connector call attempts into daily reliability metrics';

    public function __construct(
        private ConnectorReliabilityService $connectorReliabilityService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dayInput = $this->option('day');
        $day = $dayInput ? Carbon::parse($dayInput) : now()->subDay();

        $this->connectorReliabilityService->rollupDaily($day);

        $this->info('Connector metrics rolled up for '.$day->toDateString());

        return self::SUCCESS;
    }
}
