<?php

namespace App\Services;

use App\Models\Execution;
use App\Models\ExecutionRunbook;

class RunbookService
{
    /**
     * @param  array<string, mixed>|null  $error
     */
    public function ensureFailureRunbook(Execution $execution, ?array $error = null): ExecutionRunbook
    {
        $failedNode = $execution->nodes()->where('status', 'failed')->latest('sequence')->first();
        $errorMessage = $error['message'] ?? $execution->error['message'] ?? 'Execution failed with unknown error.';

        $severity = 'medium';
        if (str_contains(strtolower($errorMessage), 'timeout') || str_contains(strtolower($errorMessage), 'rate')) {
            $severity = 'high';
        }

        if (str_contains(strtolower($errorMessage), 'authentication') || str_contains(strtolower($errorMessage), 'permission')) {
            $severity = 'critical';
        }

        $steps = [
            [
                'title' => 'Inspect failed node',
                'description' => sprintf(
                    'Open execution node %s and inspect input/output payload and connector response.',
                    $failedNode?->node_id ?? 'unknown'
                ),
            ],
            [
                'title' => 'Verify credentials and policy',
                'description' => 'Confirm credential validity and workspace policy restrictions for this node.',
            ],
            [
                'title' => 'Run deterministic replay',
                'description' => 'Use deterministic replay to verify whether this is data-dependent or deterministic failure.',
            ],
            [
                'title' => 'Apply mitigation',
                'description' => 'Add retry/backoff, fallback path, or schema guard based on the failure pattern.',
            ],
        ];

        return ExecutionRunbook::query()->updateOrCreate(
            ['execution_id' => $execution->id],
            [
                'workspace_id' => $execution->workspace_id,
                'workflow_id' => $execution->workflow_id,
                'severity' => $severity,
                'title' => 'Execution Failure Runbook',
                'summary' => $errorMessage,
                'steps' => $steps,
                'tags' => [
                    'auto-generated',
                    'execution-failure',
                    $failedNode?->node_type ?? 'unknown-node',
                ],
                'status' => 'open',
            ]
        );
    }
}
