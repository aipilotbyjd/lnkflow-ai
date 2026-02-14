<?php

namespace App\Services;

use App\Jobs\ExecuteWorkflowJob;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\RateLimiter;

class WorkflowDispatchService
{
    public function __construct(
        private WorkspacePolicyService $workspacePolicyService,
        private ContractCompilerService $contractCompilerService,
        private DeterministicReplayService $deterministicReplayService
    ) {}

    /**
     * Dispatch a workflow for execution.
     *
     * @return array{execution: Execution, job_id: string}
     */
    public function dispatch(
        Workflow $workflow,
        string $mode = 'manual',
        array $triggerData = [],
        ?User $triggeredBy = null,
        string $priority = 'default'
    ): array {
        // Validate workflow can be executed
        $this->validateWorkflow($workflow);
        $this->validateContracts($workflow);
        $this->validatePolicy($workflow);

        // Check rate limit for workspace
        $this->checkRateLimit($workflow);

        // Create execution record
        $execution = Execution::create([
            'workflow_id' => $workflow->id,
            'workspace_id' => $workflow->workspace_id,
            'status' => 'pending',
            'mode' => $mode,
            'triggered_by' => $triggeredBy?->id,
            'trigger_data' => $triggerData,
            'attempt' => 1,
            'max_attempts' => $workflow->settings['retry']['max_attempts'] ?? 1,
        ]);

        $this->deterministicReplayService->capture(
            execution: $execution,
            mode: 'capture',
            triggerData: $triggerData
        );

        // Create and dispatch job
        $job = new ExecuteWorkflowJob(
            workflow: $workflow,
            execution: $execution,
            priority: $priority,
            triggerData: $triggerData,
        );

        dispatch($job);

        return [
            'execution' => $execution,
            'job_id' => $job->jobId,
        ];
    }

    /**
     * Dispatch with high priority (for webhooks, manual triggers).
     */
    public function dispatchHighPriority(
        Workflow $workflow,
        string $mode,
        array $triggerData = [],
        ?User $triggeredBy = null
    ): array {
        return $this->dispatch($workflow, $mode, $triggerData, $triggeredBy, 'high');
    }

    /**
     * Dispatch with low priority (for scheduled, bulk).
     */
    public function dispatchLowPriority(
        Workflow $workflow,
        string $mode,
        array $triggerData = [],
        ?User $triggeredBy = null
    ): array {
        return $this->dispatch($workflow, $mode, $triggerData, $triggeredBy, 'low');
    }

    /**
     * Validate workflow is ready for execution.
     */
    protected function validateWorkflow(Workflow $workflow): void
    {
        if (! $workflow->is_active) {
            throw new \RuntimeException('Workflow is not active.');
        }

        if (empty($workflow->nodes)) {
            throw new \RuntimeException('Workflow has no nodes.');
        }
    }

    protected function validateContracts(Workflow $workflow): void
    {
        $result = $this->contractCompilerService->validateAndSnapshot($workflow);
        if ($result['status'] === 'invalid') {
            throw new \RuntimeException('Workflow contract validation failed. Fix data-contract issues first.');
        }
    }

    protected function validatePolicy(Workflow $workflow): void
    {
        $violations = $this->workspacePolicyService->violations($workflow->workspace, $workflow->nodes ?? []);
        if ($violations !== []) {
            throw new \RuntimeException('Workflow violates workspace policy: '.json_encode($violations));
        }
    }

    /**
     * Check workspace rate limit.
     */
    protected function checkRateLimit(Workflow $workflow): void
    {
        $key = "workflow-dispatch:{$workflow->workspace_id}";

        if (RateLimiter::tooManyAttempts($key, 100)) {
            $seconds = RateLimiter::availableIn($key);
            throw new \RuntimeException("Rate limit exceeded. Try again in {$seconds} seconds.");
        }

        RateLimiter::hit($key, 60);
    }
}
