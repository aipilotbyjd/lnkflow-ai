<?php

namespace Database\Factories;

use App\Enums\ExecutionMode;
use App\Enums\ExecutionStatus;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Execution>
 */
class ExecutionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = $this->faker->dateTimeBetween('-1 week', 'now');
        $finishedAt = (clone $startedAt)->modify('+'.rand(100, 5000).' milliseconds');

        return [
            'workflow_id' => Workflow::factory(),
            'workspace_id' => fn (array $attributes) => Workflow::find($attributes['workflow_id'])?->workspace_id ?? Workspace::factory(),
            'status' => ExecutionStatus::Completed,
            'mode' => ExecutionMode::Manual,
            'triggered_by' => User::factory(),
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => rand(100, 5000),
            'trigger_data' => ['source' => 'test'],
            'result_data' => ['success' => true],
            'error' => null,
            'attempt' => 1,
            'max_attempts' => 3,
            'parent_execution_id' => null,
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => ExecutionStatus::Pending,
            'started_at' => null,
            'finished_at' => null,
            'duration_ms' => null,
            'result_data' => null,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => ExecutionStatus::Running,
            'started_at' => now(),
            'finished_at' => null,
            'duration_ms' => null,
            'result_data' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ExecutionStatus::Completed,
            'result_data' => ['success' => true],
            'error' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => ExecutionStatus::Failed,
            'result_data' => null,
            'error' => [
                'message' => 'Execution failed',
                'code' => 'EXECUTION_ERROR',
            ],
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => ExecutionStatus::Cancelled,
            'result_data' => null,
            'error' => null,
        ]);
    }

    public function webhook(): static
    {
        return $this->state(fn () => [
            'mode' => ExecutionMode::Webhook,
            'triggered_by' => null,
            'trigger_data' => [
                'body' => ['event' => 'test'],
                'headers' => ['content-type' => 'application/json'],
            ],
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn () => [
            'mode' => ExecutionMode::Schedule,
            'triggered_by' => null,
        ]);
    }

    public function retry(): static
    {
        return $this->state(fn () => [
            'mode' => ExecutionMode::Retry,
        ]);
    }
}
