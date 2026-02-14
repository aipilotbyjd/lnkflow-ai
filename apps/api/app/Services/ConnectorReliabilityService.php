<?php

namespace App\Services;

use App\Models\ConnectorCallAttempt;
use App\Models\ConnectorMetricDaily;
use App\Models\Execution;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ConnectorReliabilityService
{
    /**
     * @param  array<int, array<string, mixed>>  $attempts
     */
    public function ingestAttempts(Execution $execution, array $attempts): void
    {
        if ($attempts === []) {
            return;
        }

        $nodeMap = $execution->nodes()->get()->keyBy('node_id');

        foreach ($attempts as $attempt) {
            $nodeId = $attempt['node_id'] ?? null;
            $executionNode = $nodeId ? $nodeMap->get($nodeId) : null;

            ConnectorCallAttempt::query()->create([
                'execution_id' => $execution->id,
                'execution_node_id' => $executionNode?->id,
                'workspace_id' => $execution->workspace_id,
                'workflow_id' => $execution->workflow_id,
                'connector_key' => $attempt['connector_key'] ?? 'unknown',
                'connector_operation' => $attempt['connector_operation'] ?? 'execute',
                'provider' => $attempt['provider'] ?? null,
                'attempt_no' => (int) ($attempt['attempt_no'] ?? 1),
                'is_retry' => (bool) ($attempt['is_retry'] ?? false),
                'status' => $attempt['status'] ?? 'success',
                'status_code' => $attempt['status_code'] ?? null,
                'duration_ms' => $attempt['duration_ms'] ?? null,
                'request_fingerprint' => $attempt['request_fingerprint'] ?? hash('sha256', json_encode($attempt)),
                'idempotency_key' => $attempt['idempotency_key'] ?? null,
                'error_code' => $attempt['error_code'] ?? null,
                'error_message' => $attempt['error_message'] ?? null,
                'meta' => $attempt['meta'] ?? null,
                'happened_at' => isset($attempt['happened_at']) ? Carbon::parse($attempt['happened_at']) : now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function metrics(Workspace $workspace, array $filters = []): array
    {
        $query = ConnectorCallAttempt::query()->where('workspace_id', $workspace->id);

        if (! empty($filters['from'])) {
            $query->where('happened_at', '>=', Carbon::parse((string) $filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('happened_at', '<=', Carbon::parse((string) $filters['to'])->endOfDay());
        }

        if (! empty($filters['connector_key'])) {
            $query->where('connector_key', $filters['connector_key']);
        }

        if (! empty($filters['operation'])) {
            $query->where('connector_operation', $filters['operation']);
        }

        $rows = $query
            ->selectRaw('connector_key, connector_operation')
            ->selectRaw('COUNT(*) as total_calls')
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_calls")
            ->selectRaw("SUM(CASE WHEN status <> 'success' THEN 1 ELSE 0 END) as failure_calls")
            ->selectRaw('SUM(CASE WHEN is_retry = true THEN 1 ELSE 0 END) as retry_calls')
            ->selectRaw("SUM(CASE WHEN status = 'timeout' THEN 1 ELSE 0 END) as timeout_calls")
            ->selectRaw('AVG(duration_ms) as avg_latency_ms')
            ->groupBy(['connector_key', 'connector_operation'])
            ->orderByDesc('total_calls')
            ->get();

        return $rows->map(function ($row): array {
            $total = (int) $row->total_calls;
            $success = (int) $row->success_calls;
            $failures = (int) $row->failure_calls;
            $retry = (int) $row->retry_calls;
            $successRate = $total > 0 ? round(($success / $total) * 100, 2) : 0.0;
            $retryRate = $total > 0 ? round(($retry / $total) * 100, 2) : 0.0;
            $score = $this->score($successRate, $retryRate, (float) $row->avg_latency_ms);

            return [
                'connector_key' => $row->connector_key,
                'connector_operation' => $row->connector_operation,
                'total_calls' => $total,
                'success_calls' => $success,
                'failure_calls' => $failures,
                'retry_calls' => $retry,
                'timeout_calls' => (int) $row->timeout_calls,
                'success_rate' => $successRate,
                'retry_rate' => $retryRate,
                'avg_latency_ms' => $row->avg_latency_ms ? round((float) $row->avg_latency_ms, 2) : null,
                'quality_score' => $score,
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function attempts(Workspace $workspace, string $connectorKey, array $filters = []): LengthAwarePaginator
    {
        $query = ConnectorCallAttempt::query()
            ->where('workspace_id', $workspace->id)
            ->where('connector_key', $connectorKey)
            ->with(['execution', 'executionNode']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['workflow_id'])) {
            $query->where('workflow_id', $filters['workflow_id']);
        }

        return $query->latest('happened_at')->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function rollupDaily(Carbon $day): void
    {
        $start = $day->copy()->startOfDay();
        $end = $day->copy()->endOfDay();

        $attempts = ConnectorCallAttempt::query()
            ->whereBetween('happened_at', [$start, $end])
            ->get();

        DB::transaction(function () use ($attempts, $day): void {
            $grouped = $attempts->groupBy(static fn (ConnectorCallAttempt $attempt): string => implode('|', [
                (string) $attempt->workspace_id,
                (string) $attempt->connector_key,
                (string) $attempt->connector_operation,
            ]));

            foreach ($grouped as $group) {
                /** @var ConnectorCallAttempt $first */
                $first = $group->first();

                $latencies = $group->pluck('duration_ms')
                    ->filter(static fn ($value): bool => $value !== null)
                    ->map(static fn ($value): int => (int) $value)
                    ->all();

                ConnectorMetricDaily::query()->updateOrCreate(
                    [
                        'workspace_id' => $first->workspace_id,
                        'connector_key' => $first->connector_key,
                        'connector_operation' => $first->connector_operation,
                        'day' => $day->toDateString(),
                    ],
                    [
                        'total_calls' => $group->count(),
                        'success_calls' => $group->where('status', 'success')->count(),
                        'failure_calls' => $group->where('status', '!=', 'success')->count(),
                        'retry_calls' => $group->where('is_retry', true)->count(),
                        'timeout_calls' => $group->where('status', 'timeout')->count(),
                        'p50_latency_ms' => $this->percentile($latencies, 0.50),
                        'p95_latency_ms' => $this->percentile($latencies, 0.95),
                        'p99_latency_ms' => $this->percentile($latencies, 0.99),
                    ]
                );
            }
        });
    }

    /**
     * @param  array<int, int>  $values
     */
    private function percentile(array $values, float $percentile): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $index = (int) floor($percentile * (count($values) - 1));

        return $values[$index] ?? null;
    }

    private function score(float $successRate, float $retryRate, ?float $avgLatencyMs): int
    {
        $latencyPenalty = 0.0;
        if ($avgLatencyMs !== null) {
            $latencyPenalty = min(30, $avgLatencyMs / 200);
        }

        $base = ($successRate * 0.8) - ($retryRate * 0.2) - $latencyPenalty;

        return max(0, min(100, (int) round($base)));
    }
}
