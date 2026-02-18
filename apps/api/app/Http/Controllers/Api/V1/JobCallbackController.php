<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LogLevel;
use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Models\ExecutionLog;
use App\Models\ExecutionNode;
use App\Models\JobStatus;
use App\Services\ConnectorReliabilityService;
use App\Services\CostOptimizerService;
use App\Services\DeterministicReplayService;
use App\Jobs\AnalyzeFailedExecution;
use App\Services\RunbookService;
use App\Services\WorkflowApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class JobCallbackController extends Controller
{
    public function __construct(
        private ConnectorReliabilityService $connectorReliabilityService,
        private DeterministicReplayService $deterministicReplayService,
        private WorkflowApprovalService $workflowApprovalService,
        private RunbookService $runbookService,
        private CostOptimizerService $costOptimizerService
    ) {}

    /**
     * Handle callback from Go engine.
     */
    public function handle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'job_id' => 'required|uuid',
            'callback_token' => 'required|string|size:64', // Required token
            'execution_id' => 'required|integer',
            'status' => 'required|in:completed,failed,waiting',
            'nodes' => 'nullable|array',
            'nodes.*.node_id' => 'required|string',
            'nodes.*.node_type' => 'required|string',
            'nodes.*.node_name' => 'nullable|string',
            'nodes.*.status' => 'required|in:pending,running,completed,failed,skipped',
            'nodes.*.output' => 'nullable|array',
            'nodes.*.error' => 'nullable|array',
            'nodes.*.started_at' => 'nullable|date',
            'nodes.*.completed_at' => 'nullable|date',
            'nodes.*.sequence' => 'nullable|integer',
            'error' => 'nullable|array',
            'duration_ms' => 'nullable|integer',
            'deterministic_fixtures' => 'nullable|array',
            'deterministic_fixtures.*.request_fingerprint' => 'nullable|string|max:64',
            'deterministic_fixtures.*.request' => 'nullable|array',
            'deterministic_fixtures.*.response' => 'nullable|array',
            'connector_attempts' => 'nullable|array',
            'connector_attempts.*.node_id' => 'nullable|string|max:100',
            'connector_attempts.*.connector_key' => 'required_with:connector_attempts|string|max:120',
            'connector_attempts.*.connector_operation' => 'required_with:connector_attempts|string|max:120',
            'connector_attempts.*.provider' => 'nullable|string|max:191',
            'connector_attempts.*.attempt_no' => 'nullable|integer|min:1|max:100',
            'connector_attempts.*.is_retry' => 'nullable|boolean',
            'connector_attempts.*.status' => 'required_with:connector_attempts|in:success,client_error,server_error,timeout,network_error,cancelled',
            'connector_attempts.*.status_code' => 'nullable|integer|min:100|max:599',
            'connector_attempts.*.duration_ms' => 'nullable|integer|min:0',
            'connector_attempts.*.request_fingerprint' => 'nullable|string|size:64',
            'connector_attempts.*.idempotency_key' => 'nullable|string|max:191',
            'connector_attempts.*.error_code' => 'nullable|string|max:120',
            'connector_attempts.*.error_message' => 'nullable|string',
            'connector_attempts.*.happened_at' => 'nullable|date',
            'connector_attempts.*.meta' => 'nullable|array',
        ]);

        // Find job status
        $jobStatus = JobStatus::where('job_id', $validated['job_id'])->first();

        if (! $jobStatus) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        // Validate callback token (timing-safe comparison)
        if (! hash_equals($jobStatus->callback_token, $validated['callback_token'])) {
            return response()->json(['error' => 'Invalid callback token'], 401);
        }

        // Idempotent handling for repeated callbacks from retries.
        if (in_array($jobStatus->status, ['completed', 'failed'], true)) {
            return response()->json([
                'success' => true,
                'execution_id' => $jobStatus->execution_id,
                'status' => $jobStatus->status,
                'idempotent' => true,
            ]);
        }

        // Find execution
        $execution = Execution::find($validated['execution_id']);

        if (! $execution) {
            return response()->json(['error' => 'Execution not found'], 404);
        }

        if ((int) $jobStatus->execution_id !== (int) $execution->id) {
            return response()->json(['error' => 'Execution does not match job'], 403);
        }

        DB::transaction(function () use ($execution, $jobStatus, $validated): void {
            // Update execution nodes and create execution logs
            if (! empty($validated['nodes'])) {
                foreach ($validated['nodes'] as $nodeData) {
                    $executionNode = ExecutionNode::updateOrCreate(
                        [
                            'execution_id' => $execution->id,
                            'node_id' => $nodeData['node_id'],
                        ],
                        [
                            'node_type' => $nodeData['node_type'],
                            'node_name' => $nodeData['node_name'] ?? null,
                            'status' => $nodeData['status'],
                            'output_data' => $nodeData['output'] ?? null,
                            'error' => $nodeData['error'] ?? null,
                            'started_at' => $nodeData['started_at'] ?? null,
                            'finished_at' => $nodeData['completed_at'] ?? null,
                            'sequence' => $nodeData['sequence'] ?? 0,
                        ]
                    );

                    $nodeName = $nodeData['node_name'] ?? $nodeData['node_id'];
                    $nodeStatus = $nodeData['status'];

                    if ($nodeStatus === 'failed') {
                        $errorMessage = $nodeData['error']['message'] ?? 'Unknown error';
                        ExecutionLog::create([
                            'execution_id' => $execution->id,
                            'execution_node_id' => $executionNode->id,
                            'level' => LogLevel::Error,
                            'message' => "Node '{$nodeName}' failed: {$errorMessage}",
                            'context' => $nodeData['error'] ?? null,
                            'logged_at' => $nodeData['completed_at'] ?? now(),
                        ]);
                    } else {
                        ExecutionLog::create([
                            'execution_id' => $execution->id,
                            'execution_node_id' => $executionNode->id,
                            'level' => LogLevel::Info,
                            'message' => "Node '{$nodeName}' ({$nodeData['node_type']}) {$nodeStatus}",
                            'context' => null,
                            'logged_at' => $nodeData['completed_at'] ?? $nodeData['started_at'] ?? now(),
                        ]);
                    }

                    // Publish SSE event for node status
                    $this->publishStreamEvent($execution->id, [
                        'event' => $nodeStatus === 'failed' ? 'node.failed' : ($nodeStatus === 'completed' ? 'node.completed' : 'node.started'),
                        'execution_id' => $execution->id,
                        'node_key' => $nodeData['node_id'],
                        'data' => [
                            'status' => $nodeStatus,
                            'duration_ms' => isset($nodeData['started_at'], $nodeData['completed_at'])
                                ? (int) (strtotime($nodeData['completed_at']) - strtotime($nodeData['started_at'])) * 1000
                                : null,
                            'output_summary' => $nodeStatus === 'completed' ? [
                                'output_keys' => is_array($nodeData['output'] ?? null) ? array_keys($nodeData['output']) : [],
                            ] : null,
                            'error' => $nodeStatus === 'failed' ? ($nodeData['error']['message'] ?? null) : null,
                        ],
                        'timestamp' => $nodeData['completed_at'] ?? $nodeData['started_at'] ?? now()->toIso8601String(),
                    ]);

                    if (($nodeData['node_type'] ?? '') === 'action_approval'
                        && in_array($validated['status'], ['waiting', 'failed'], true)
                    ) {
                        $this->workflowApprovalService->createPendingApproval(
                            execution: $execution,
                            nodeId: $nodeData['node_id'],
                            title: $nodeName.' requires approval',
                            description: $nodeData['error']['message'] ?? 'Approval required before workflow can continue.',
                            payload: [
                                'node' => $nodeData,
                                'execution_id' => $execution->id,
                            ]
                        );
                    }
                }
            }

            $startedAt = $execution->started_at ?? (! empty($validated['nodes']) ? $validated['nodes'][0]['started_at'] ?? now() : now());

            $execution->update([
                'status' => $validated['status'],
                'started_at' => $startedAt,
                'finished_at' => $validated['status'] === 'waiting' ? null : now(),
                'duration_ms' => $validated['duration_ms'] ?? null,
                'error' => $validated['error'] ?? null,
            ]);

            if ($validated['status'] === 'completed') {
                $jobStatus->markCompleted([
                    'duration_ms' => $validated['duration_ms'] ?? null,
                    'nodes_count' => count($validated['nodes'] ?? []),
                ]);
            } elseif ($validated['status'] === 'failed') {
                $jobStatus->markFailed($validated['error'] ?? ['message' => 'Unknown error']);
            } else {
                $jobStatus->updateProgress(90);
            }

            if (! empty($validated['connector_attempts'])) {
                $this->connectorReliabilityService->ingestAttempts($execution, $validated['connector_attempts']);
            }

            if (! empty($validated['deterministic_fixtures'])) {
                $this->deterministicReplayService->appendFixtures($execution, $validated['deterministic_fixtures']);
            }

            if ($validated['status'] === 'failed') {
                $this->runbookService->ensureFailureRunbook($execution->fresh(['nodes']), $validated['error'] ?? null);
            }

            // Publish execution-level SSE event
            if (in_array($validated['status'], ['completed', 'failed'], true)) {
                $this->publishStreamEvent($execution->id, [
                    'event' => $validated['status'] === 'completed' ? 'execution.completed' : 'execution.failed',
                    'execution_id' => $execution->id,
                    'data' => [
                        'status' => $validated['status'],
                        'total_duration_ms' => $validated['duration_ms'] ?? null,
                        'node_count' => count($validated['nodes'] ?? []),
                        'error' => $validated['status'] === 'failed' ? ($validated['error']['message'] ?? null) : null,
                    ],
                    'timestamp' => now()->toIso8601String(),
                ]);
            }

            // Dispatch AI auto-fix analysis for failed executions
            if ($validated['status'] === 'failed') {
                AnalyzeFailedExecution::dispatch($execution->fresh());
            }

            if (! empty($validated['connector_attempts'])) {
                $execution->load('connectorAttempts');
                $this->costOptimizerService->calculateExecutionEstimatedCost($execution);
            }
        });

        return response()->json([
            'success' => true,
            'execution_id' => $execution->id,
            'status' => $validated['status'],
        ]);
    }

    /**
     * Handle progress update from Go engine.
     */
    public function progress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'job_id' => 'required|uuid',
            'callback_token' => 'required|string|size:64',
            'progress' => 'required|integer|min:0|max:100',
            'current_node' => 'nullable|string',
            'connector_attempts' => 'nullable|array',
            'connector_attempts.*.node_id' => 'nullable|string|max:100',
            'connector_attempts.*.connector_key' => 'required_with:connector_attempts|string|max:120',
            'connector_attempts.*.connector_operation' => 'required_with:connector_attempts|string|max:120',
            'connector_attempts.*.provider' => 'nullable|string|max:191',
            'connector_attempts.*.attempt_no' => 'nullable|integer|min:1|max:100',
            'connector_attempts.*.is_retry' => 'nullable|boolean',
            'connector_attempts.*.status' => 'required_with:connector_attempts|in:success,client_error,server_error,timeout,network_error,cancelled',
            'connector_attempts.*.status_code' => 'nullable|integer|min:100|max:599',
            'connector_attempts.*.duration_ms' => 'nullable|integer|min:0',
            'connector_attempts.*.request_fingerprint' => 'nullable|string|size:64',
            'connector_attempts.*.idempotency_key' => 'nullable|string|max:191',
            'connector_attempts.*.error_code' => 'nullable|string|max:120',
            'connector_attempts.*.error_message' => 'nullable|string',
            'connector_attempts.*.happened_at' => 'nullable|date',
            'connector_attempts.*.meta' => 'nullable|array',
            'deterministic_fixtures' => 'nullable|array',
        ]);

        $jobStatus = JobStatus::where('job_id', $validated['job_id'])->first();

        if (! $jobStatus) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        // Validate callback token
        if (! hash_equals($jobStatus->callback_token, $validated['callback_token'])) {
            return response()->json(['error' => 'Invalid callback token'], 401);
        }

        if (in_array($jobStatus->status, ['completed', 'failed'], true)) {
            return response()->json(['success' => true, 'idempotent' => true]);
        }

        $jobStatus->updateProgress($validated['progress']);

        // Publish progress to SSE stream
        $this->publishStreamEvent($jobStatus->execution_id, [
            'event' => 'node.started',
            'execution_id' => $jobStatus->execution_id,
            'node_key' => $validated['current_node'] ?? null,
            'data' => [
                'progress' => $validated['progress'],
            ],
            'timestamp' => now()->toIso8601String(),
        ]);

        if (! empty($validated['connector_attempts']) || ! empty($validated['deterministic_fixtures'])) {
            $execution = Execution::query()->find($jobStatus->execution_id);
            if ($execution) {
                if (! empty($validated['connector_attempts'])) {
                    $this->connectorReliabilityService->ingestAttempts($execution, $validated['connector_attempts']);
                }

                if (! empty($validated['deterministic_fixtures'])) {
                    $this->deterministicReplayService->appendFixtures($execution, $validated['deterministic_fixtures']);
                }

                if (! empty($validated['connector_attempts'])) {
                    $execution->load('connectorAttempts');
                    $this->costOptimizerService->calculateExecutionEstimatedCost($execution);
                }
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * Publish an event to the execution's SSE Redis stream.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function publishStreamEvent(int $executionId, array $eventData): void
    {
        $channelKey = "execution:{$executionId}:events";

        try {
            Redis::xadd($channelKey, '*', [
                'payload' => json_encode($eventData, JSON_UNESCAPED_SLASHES),
            ]);

            Redis::expire($channelKey, 300);
        } catch (\Throwable $e) {
            Log::warning('Failed to publish SSE event', [
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
