<?php

namespace Database\Factories;

use App\Models\WorkspaceUsagePeriod;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceUsagePeriod>
 */
class WorkspaceUsagePeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'subscription_id' => null,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'credits_limit' => 1000,
            'credits_used' => 0,
            'credits_overage' => 0,
            'executions_total' => 0,
            'executions_succeeded' => 0,
            'executions_failed' => 0,
            'nodes_executed' => 0,
            'ai_nodes_executed' => 0,
            'data_transfer_bytes' => 0,
            'estimated_cost_usd' => 0,
            'active_workflows_count' => 0,
            'members_count' => 1,
            'is_current' => true,
            'is_overage_billed' => false,
            'stripe_invoice_id' => null,
        ];
    }

    public function exhausted(): static
    {
        return $this->state(fn (array $attributes) => [
            'credits_used' => $attributes['credits_limit'],
        ]);
    }

    public function past(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
            'is_current' => false,
        ]);
    }
}
