<?php

namespace App\Services;

use App\Enums\ExecutionMode;
use App\Enums\ExecutionStatus;
use App\Jobs\ExecuteWorkflowJob;
use App\Models\Execution;
use App\Models\WorkflowApproval;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class WorkflowApprovalService
{
    public function inbox(Workspace $workspace, array $filters = []): LengthAwarePaginator
    {
        $query = WorkflowApproval::query()
            ->where('workspace_id', $workspace->id)
            ->with(['workflow', 'execution', 'approvedBy'])
            ->latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['workflow_id'])) {
            $query->where('workflow_id', $filters['workflow_id']);
        }

        return $query->paginate((int) ($filters['per_page'] ?? 20));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createPendingApproval(Execution $execution, string $nodeId, string $title, ?string $description = null, array $payload = []): WorkflowApproval
    {
        return WorkflowApproval::query()->firstOrCreate(
            [
                'execution_id' => $execution->id,
                'node_id' => $nodeId,
                'status' => 'pending',
            ],
            [
                'workspace_id' => $execution->workspace_id,
                'workflow_id' => $execution->workflow_id,
                'title' => $title,
                'description' => $description,
                'payload' => $payload,
                'due_at' => now()->addHours(24),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $decisionPayload
     */
    public function decide(WorkflowApproval $approval, int $userId, string $decision, array $decisionPayload = []): Execution
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('Decision must be approved or rejected.');
        }

        if ($approval->status !== 'pending') {
            throw new \RuntimeException('Approval is no longer pending.');
        }

        $approval->update([
            'status' => $decision,
            'approved_by' => $userId,
            'approved_at' => now(),
            'decision_payload' => $decisionPayload,
        ]);

        $execution = $approval->execution;
        $workflow = $execution->workflow;

        $newExecution = DB::transaction(function () use ($execution, $userId, $decision, $decisionPayload, $approval) {
            return Execution::query()->create([
                'workflow_id' => $execution->workflow_id,
                'workspace_id' => $execution->workspace_id,
                'status' => ExecutionStatus::Pending,
                'mode' => ExecutionMode::Retry,
                'triggered_by' => $userId,
                'trigger_data' => array_merge($execution->trigger_data ?? [], [
                    'approval' => [
                        'approval_id' => $approval->id,
                        'decision' => $decision,
                        'decision_payload' => $decisionPayload,
                    ],
                ]),
                'attempt' => 1,
                'max_attempts' => $execution->max_attempts,
                'parent_execution_id' => $execution->id,
            ]);
        });

        ExecuteWorkflowJob::dispatch(
            $workflow,
            $newExecution,
            'default',
            $newExecution->trigger_data ?? []
        )->afterCommit();

        return $newExecution;
    }
}
