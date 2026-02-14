<?php

namespace App\Services;

use App\Models\Workflow;
use App\Models\WorkflowContractTestRun;
use App\Models\Workspace;

class WorkflowContractTestService
{
    public function __construct(
        private ContractCompilerService $contractCompilerService
    ) {}

    /**
     * @return array{run: WorkflowContractTestRun, passed: bool, issues: array<int, array<string, mixed>>}
     */
    public function runForWorkflow(Workflow $workflow, bool $strict = true): array
    {
        $validation = $this->contractCompilerService->validateAndSnapshot($workflow, strict: $strict);
        $passed = $validation['status'] === 'valid' || (! $strict && $validation['status'] === 'warning');

        $run = WorkflowContractTestRun::query()->create([
            'workspace_id' => $workflow->workspace_id,
            'workflow_id' => $workflow->id,
            'workflow_contract_snapshot_id' => $validation['snapshot']->id,
            'status' => $passed ? 'passed' : 'failed',
            'results' => [
                'status' => $validation['status'],
                'issues' => $validation['issues'],
            ],
            'executed_at' => now(),
        ]);

        return [
            'run' => $run,
            'passed' => $passed,
            'issues' => $validation['issues'],
        ];
    }

    /**
     * @return array{total: int, passed: int, failed: int, runs: array<int, array<string, mixed>>}
     */
    public function runForWorkspace(Workspace $workspace, bool $strict = true): array
    {
        $workflows = $workspace->workflows()->get();

        $runs = [];
        $passed = 0;
        $failed = 0;

        foreach ($workflows as $workflow) {
            $result = $this->runForWorkflow($workflow, $strict);
            $runs[] = [
                'workflow_id' => $workflow->id,
                'workflow_name' => $workflow->name,
                'passed' => $result['passed'],
                'issues_count' => count($result['issues']),
                'run_id' => $result['run']->id,
            ];

            if ($result['passed']) {
                $passed++;
            } else {
                $failed++;
            }
        }

        return [
            'total' => count($runs),
            'passed' => $passed,
            'failed' => $failed,
            'runs' => $runs,
        ];
    }
}
