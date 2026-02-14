<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\WorkflowContractTestService;
use Illuminate\Console\Command;

class RunWorkflowContractTests extends Command
{
    /**
     * @var string
     */
    protected $signature = 'workflows:contract-test
                            {--workspace= : Workspace ID to scope tests}
                            {--non-strict : Treat warnings as pass}';

    /**
     * @var string
     */
    protected $description = 'Run workflow contract tests for CI and regression checks';

    public function __construct(
        private WorkflowContractTestService $contractTestService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $workspaceId = $this->option('workspace');
        $strict = ! $this->option('non-strict');

        if ($workspaceId) {
            $workspace = Workspace::query()->findOrFail($workspaceId);
            $summary = $this->contractTestService->runForWorkspace($workspace, $strict);

            $this->line("Workspace: {$workspace->name} (#{$workspace->id})");
            foreach ($summary['runs'] as $run) {
                $status = $run['passed'] ? 'PASS' : 'FAIL';
                $this->line("[{$status}] {$run['workflow_name']} (#{$run['workflow_id']}) issues={$run['issues_count']}");
            }

            $this->info("Passed: {$summary['passed']} / {$summary['total']}");

            return $summary['failed'] === 0 ? self::SUCCESS : self::FAILURE;
        }

        $overallTotal = 0;
        $overallPassed = 0;
        $overallFailed = 0;

        Workspace::query()->chunk(50, function ($workspaces) use ($strict, &$overallTotal, &$overallPassed, &$overallFailed): void {
            foreach ($workspaces as $workspace) {
                $summary = $this->contractTestService->runForWorkspace($workspace, $strict);
                $overallTotal += $summary['total'];
                $overallPassed += $summary['passed'];
                $overallFailed += $summary['failed'];
            }
        });

        $this->info("Contract tests: passed={$overallPassed} failed={$overallFailed} total={$overallTotal}");

        return $overallFailed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
