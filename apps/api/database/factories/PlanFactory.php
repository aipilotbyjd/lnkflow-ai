<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'slug' => fake()->unique()->slug(),
            'description' => fake()->sentence(),
            'price_monthly' => fake()->randomElement([0, 900, 2900, 9900]),
            'price_yearly' => fake()->randomElement([0, 8400, 28800, 94800]),
            'limits' => [
                'credits_monthly' => 1000,
                'active_workflows' => 3,
                'members' => 1,
                'min_schedule_interval' => 15,
                'max_execution_time' => 300,
                'max_file_size_mb' => 5,
                'data_transfer_gb' => 0.5,
                'concurrent_executions' => 1,
                'api_rate_limit_per_min' => 0,
                'execution_log_days' => 3,
                'max_saved_executions' => 500,
                'webhooks' => 1,
                'credentials' => 5,
                'variables' => 10,
                'workflow_versions' => 3,
                'ai_generation_daily' => 5,
            ],
            'features' => [
                'webhooks' => false,
                'api_access' => false,
                'custom_variables' => false,
                'sub_workflows' => false,
                'parallel_execution' => false,
                'priority_execution' => false,
                'full_text_log_search' => false,
                'deterministic_replay' => false,
                'import_export' => false,
                'ai_autofix' => false,
                'ai_generation' => false,
                'approval_workflows' => false,
                'workspace_policies' => false,
                'environments' => false,
                'analytics_dashboard' => false,
                'connector_metrics' => false,
                'execution_runbooks' => false,
                'audit_logs' => false,
                'sso_saml' => false,
                'custom_node_types' => false,
                'overage_protection' => false,
                'priority_support' => false,
            ],
            'stripe_product_id' => null,
            'stripe_prices' => null,
            'credit_tiers' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Free',
            'slug' => 'free',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'sort_order' => 0,
        ]);
    }

    public function pro(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Pro',
            'slug' => 'pro',
            'price_monthly' => 2900,
            'price_yearly' => 28800,
            'limits' => [
                ...$attributes['limits'],
                'credits_monthly' => 50000,
                'active_workflows' => -1,
                'members' => 10,
                'concurrent_executions' => 20,
            ],
            'features' => [
                ...$attributes['features'],
                'webhooks' => true,
                'api_access' => true,
                'custom_variables' => true,
                'sub_workflows' => true,
                'parallel_execution' => true,
                'full_text_log_search' => true,
                'deterministic_replay' => true,
                'import_export' => true,
                'ai_autofix' => true,
                'ai_generation' => true,
            ],
            'credit_tiers' => [10000, 20000, 40000, 80000, 150000],
            'sort_order' => 2,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
