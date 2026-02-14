<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ExecutionMode;
use App\Enums\ExecutionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Execution\RerunDeterministicRequest;
use App\Http\Resources\Api\V1\ExecutionLogResource;
use App\Http\Resources\Api\V1\ExecutionNodeResource;
use App\Http\Resources\Api\V1\ExecutionResource;
use App\Jobs\ExecuteWorkflowJob;
use App\Models\Execution;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\ContractCompilerService;
use App\Services\DeterministicReplayService;
use App\Services\WorkspacePolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExecutionController extends Controller
{
    public function __construct(
        private ContractCompilerService $contractCompilerService,
        private WorkspacePolicyService $workspacePolicyService,
        private DeterministicReplayService $deterministicReplayService
    ) {}

    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('execution.view');

        $query = $workspace->executions()
            ->with(['workflow', 'triggeredBy']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('workflow_id')) {
            $query->where('workflow_id', $request->input('workflow_id'));
        }

        if ($request->filled('mode')) {
            $query->where('mode', $request->input('mode'));
        }

        $executions = $query->latest()->paginate($request->input('per_page', 20));

        return ExecutionResource::collection($executions);
    }

    public function store(Request $request, Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $this->authorize('workflow.execute');

        if ($workflow->workspace_id !== $workspace->id) {
            abort(404, 'Workflow not found.');
        }

        $contractValidation = $this->contractCompilerService->validateAndSnapshot($workflow);
        if ($contractValidation['status'] === 'invalid') {
            return response()->json([
                'message' => 'Workflow contracts are invalid. Fix contract issues before executing.',
                'issues' => $contractValidation['issues'],
                'snapshot_id' => $contractValidation['snapshot']->id,
            ], 422);
        }

        $policyViolations = $this->workspacePolicyService->violations($workspace, $workflow->nodes ?? []);
        if ($policyViolations !== []) {
            return response()->json([
                'message' => 'Workflow violates workspace policy.',
                'violations' => $policyViolations,
            ], 422);
        }

        // Create execution record
        $execution = Execution::create([
            'workflow_id' => $workflow->id,
            'workspace_id' => $workspace->id,
            'status' => \App\Enums\ExecutionStatus::Pending,
            'mode' => \App\Enums\ExecutionMode::Manual,
            'triggered_by' => $request->user()->id,
            'trigger_data' => $request->input('input', []),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $this->deterministicReplayService->capture(
            execution: $execution,
            mode: 'capture',
            triggerData: $request->input('input', [])
        );

        // Dispatch job to Go Engine (via Queue)
        \App\Jobs\ExecuteWorkflowJob::dispatch($workflow, $execution, 'default', $request->input('input', []));

        $execution->load(['workflow', 'triggeredBy', 'replayPack']);

        return response()->json([
            'message' => 'Execution started successfully.',
            'execution' => new ExecutionResource($execution),
        ], 201);
    }

    public function show(Request $request, Workspace $workspace, Execution $execution): JsonResponse
    {
        $this->authorize('execution.view');
        $this->ensureExecutionBelongsToWorkspace($execution, $workspace);

        $execution->load(['workflow', 'triggeredBy', 'nodes', 'replayPack', 'runbook']);

        return response()->json([
            'execution' => new ExecutionResource($execution),
        ]);
    }

    public function destroy(Request $request, Workspace $workspace, Execution $execution): JsonResponse
    {
        $this->authorize('execution.delete');
        $this->ensureExecutionBelongsToWorkspace($execution, $workspace);

        $execution->delete();

        return response()->json([
            'message' => 'Execution deleted successfully.',
        ]);
    }

    public function nodes(Request $request, Workspace $workspace, Execution $execution): AnonymousResourceCollection
    {
        $this->authorize('execution.view');
        $this->ensureExecutionBelongsToWorkspace($execution, $workspace);

        return ExecutionNodeResource::collection($execution->nodes);
    }

    public function logs(Request $request, Workspace $workspace, Execution $execution): AnonymousResourceCollection
    {
        $this->authorize('execution.view');
        $this->ensureExecutionBelongsToWorkspace($execution, $workspace);

        $query = $execution->logs();

        if ($request->filled('level')) {
            $query->where('level', $request->input('level'));
        }

        if ($request->filled('execution_node_id')) {
            $query->where('execution_node_id', $request->input('execution_node_id'));
        }

        return ExecutionLogResource::collection($query->get());
    }

    public function retry(Request $request, Workspace $workspace, Execution $execution): JsonResponse
    {
        $this->authorize('workflow.execute');
        $this->ensureExecutionBelongsToWorkspace($execution, $workspace);

        if (! $execution->canRetry()) {
            return response()->json([
                'message' => 'This execution cannot be retried.',
            ], 422);
        }

        $newExecution = Execution::create([
            'workflow_id' => $execution->workflow_id,
            'workspace_id' => $execution->workspace_id,
            'status' => ExecutionStatus::Pending,
            'mode' => ExecutionMode::Retry,
            'triggered_by' => $request->user()->id,
            'trigger_data' => $execution->trigger_data,
            'attempt' => $execution->attempt + 1,
            'max_attempts' => $execution->max_attempts,
            'parent_execution_id' => $execution->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $this->deterministicReplayService->capture(
            execution: $newExecution,
            mode: 'capture',
            sourceExecution: $execution,
            triggerData: $execution->trigger_data ?? []
        );

        ExecuteWorkflowJob::dispatch(
            $execution->workflow,
            $newExecution,
            'default',
            $execution->trigger_data ?? []
        );

        $newExecution->load(['workflow', 'triggeredBy']);

        return response()->json([
            'message' => 'Execution retry started.',
            'execution' => new ExecutionResource($newExecution),
        ], 201);
    }

    public function rerunDeterministic(
        RerunDeterministicRequest $request,
        Workspace $workspace,
        Execution $execution
    ): JsonResponse {
        $this->authorize('workflow.execute');
        $this->ensureExecutionBelongsToWorkspace($execution, $workspace);

        if (! $execution->replayPack) {
            return response()->json([
                'message' => 'This execution does not have deterministic replay artifacts.',
            ], 422);
        }

        $result = $this->deterministicReplayService->rerunDeterministically(
            sourceExecution: $execution->load('workflow', 'replayPack'),
            user: $request->user(),
            useLatestWorkflow: $request->boolean('use_latest_workflow'),
            overrideTriggerData: $request->input('override_trigger_data')
        );

        $newExecution = $result['execution']->load(['workflow', 'triggeredBy', 'replayPack']);

        return response()->json([
            'message' => 'Deterministic replay started.',
            'replay_mode' => true,
            'execution' => new ExecutionResource($newExecution),
        ], 201);
    }

    public function cancel(Request $request, Workspace $workspace, Execution $execution): JsonResponse
    {
        $this->authorize('workflow.execute');
        $this->ensureExecutionBelongsToWorkspace($execution, $workspace);

        if (! $execution->canCancel()) {
            return response()->json([
                'message' => 'This execution cannot be cancelled.',
            ], 422);
        }

        $execution->cancel();
        $execution->load(['workflow', 'triggeredBy']);

        return response()->json([
            'message' => 'Execution cancelled.',
            'execution' => new ExecutionResource($execution),
        ]);
    }

    public function workflowExecutions(Request $request, Workspace $workspace, Workflow $workflow): AnonymousResourceCollection
    {
        $this->authorize('execution.view');

        if ($workflow->workspace_id !== $workspace->id) {
            abort(404, 'Workflow not found.');
        }

        $query = $workflow->executions()->with(['triggeredBy']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $executions = $query->latest()->paginate($request->input('per_page', 20));

        return ExecutionResource::collection($executions);
    }

    public function replayPack(Request $request, Workspace $workspace, Execution $execution): JsonResponse
    {
        $this->authorize('execution.view');
        $this->ensureExecutionBelongsToWorkspace($execution, $workspace);

        $execution->load('replayPack');

        if (! $execution->replayPack) {
            return response()->json([
                'replay_pack' => null,
                'message' => 'Replay pack not available for this execution.',
            ], 404);
        }

        return response()->json([
            'replay_pack' => [
                'id' => $execution->replayPack->id,
                'mode' => $execution->replayPack->mode,
                'deterministic_seed' => $execution->replayPack->deterministic_seed,
                'workflow_snapshot' => $execution->replayPack->workflow_snapshot,
                'trigger_snapshot' => $execution->replayPack->trigger_snapshot,
                'fixtures' => $execution->replayPack->fixtures,
                'environment_snapshot' => $execution->replayPack->environment_snapshot,
                'captured_at' => $execution->replayPack->captured_at,
                'expires_at' => $execution->replayPack->expires_at,
            ],
        ]);
    }

    public function stats(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('execution.view');

        $baseQuery = $workspace->executions();

        if ($request->filled('workflow_id')) {
            $baseQuery->where('workflow_id', $request->input('workflow_id'));
        }

        // Use single aggregation query for better performance (1 query instead of 7)
        $stats = $baseQuery
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as running,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN is_deterministic_replay = true THEN 1 ELSE 0 END) as deterministic_replays,
                AVG(CASE WHEN duration_ms IS NOT NULL THEN duration_ms END) as avg_duration_ms,
                AVG(CASE WHEN estimated_cost_usd IS NOT NULL THEN estimated_cost_usd END) as avg_estimated_cost_usd
            ', [
                ExecutionStatus::Completed->value,
                ExecutionStatus::Failed->value,
                ExecutionStatus::Running->value,
                ExecutionStatus::Pending->value,
                ExecutionStatus::Cancelled->value,
            ])
            ->first();

        $result = [
            'total' => (int) $stats->total,
            'completed' => (int) $stats->completed,
            'failed' => (int) $stats->failed,
            'running' => (int) $stats->running,
            'pending' => (int) $stats->pending,
            'cancelled' => (int) $stats->cancelled,
            'deterministic_replays' => (int) $stats->deterministic_replays,
            'avg_duration_ms' => $stats->avg_duration_ms ? round($stats->avg_duration_ms, 2) : null,
            'avg_estimated_cost_usd' => $stats->avg_estimated_cost_usd ? round((float) $stats->avg_estimated_cost_usd, 4) : null,
        ];

        $result['success_rate'] = $result['total'] > 0
            ? round(($result['completed'] / $result['total']) * 100, 2)
            : 0;

        return response()->json(['stats' => $result]);
    }

    private function ensureExecutionBelongsToWorkspace(Execution $execution, Workspace $workspace): void
    {
        if ($execution->workspace_id !== $workspace->id) {
            abort(404, 'Execution not found.');
        }
    }
}
