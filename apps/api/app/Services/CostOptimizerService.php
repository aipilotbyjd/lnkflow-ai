<?php

namespace App\Services;

use App\Models\Execution;
use App\Models\Workspace;

class CostOptimizerService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function recommendations(Workspace $workspace): array
    {
        $metrics = $workspace->connectorMetrics()->latest('day')->limit(60)->get();

        $recommendations = [];

        foreach ($metrics->groupBy('connector_key') as $connectorKey => $rows) {
            $totalCalls = (int) $rows->sum('total_calls');
            $failures = (int) $rows->sum('failure_calls');
            $retries = (int) $rows->sum('retry_calls');
            $successRate = $totalCalls > 0 ? (($totalCalls - $failures) / $totalCalls) * 100 : 0;

            if ($totalCalls < 20) {
                continue;
            }

            if ($successRate < 95) {
                $recommendations[] = [
                    'type' => 'reliability',
                    'connector_key' => $connectorKey,
                    'message' => sprintf(
                        '%s has %.2f%% success rate. Consider fallback connector or retry backoff tuning.',
                        $connectorKey,
                        $successRate
                    ),
                    'potential_savings_usd_per_month' => round($retries * 0.0015, 2),
                ];
            }

            if ($retries > 0 && $totalCalls > 0 && ($retries / $totalCalls) > 0.2) {
                $recommendations[] = [
                    'type' => 'retry_reduction',
                    'connector_key' => $connectorKey,
                    'message' => sprintf(
                        '%s retry rate is %.2f%%. Tune timeout and idempotency settings.',
                        $connectorKey,
                        ($retries / $totalCalls) * 100
                    ),
                    'potential_savings_usd_per_month' => round($retries * 0.0009, 2),
                ];
            }
        }

        return $recommendations;
    }

    public function calculateExecutionEstimatedCost(Execution $execution): float
    {
        $connectorAttempts = $execution->connectorAttempts;

        $cost = 0.0;
        foreach ($connectorAttempts as $attempt) {
            $base = match ($attempt->connector_key) {
                'openai', 'anthropic', 'ai' => 0.0025,
                'action_http_request', 'http' => 0.0004,
                default => 0.0002,
            };

            if ($attempt->is_retry) {
                $base *= 0.8;
            }

            $cost += $base;
        }

        $execution->update([
            'estimated_cost_usd' => round($cost, 4),
        ]);

        return round($cost, 4);
    }
}
