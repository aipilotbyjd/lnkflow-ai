<?php

namespace Database\Factories;

use App\Enums\ExecutionNodeStatus;
use App\Models\Execution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExecutionNode>
 */
class ExecutionNodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = $this->faker->dateTimeBetween('-1 hour', 'now');
        $finishedAt = (clone $startedAt)->modify('+'.rand(10, 500).' milliseconds');

        return [
            'execution_id' => Execution::factory(),
            'node_id' => 'node_'.$this->faker->unique()->numberBetween(1, 1000),
            'node_type' => $this->faker->randomElement(['trigger_manual', 'action_http_request', 'action_send_email']),
            'node_name' => $this->faker->words(3, true),
            'status' => ExecutionNodeStatus::Completed,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => rand(10, 500),
            'input_data' => ['key' => 'value'],
            'output_data' => ['result' => 'success'],
            'error' => null,
            'sequence' => $this->faker->numberBetween(1, 10),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => ExecutionNodeStatus::Pending,
            'started_at' => null,
            'finished_at' => null,
            'duration_ms' => null,
            'output_data' => null,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => ExecutionNodeStatus::Running,
            'started_at' => now(),
            'finished_at' => null,
            'duration_ms' => null,
            'output_data' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ExecutionNodeStatus::Completed,
            'output_data' => ['result' => 'success'],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => ExecutionNodeStatus::Failed,
            'output_data' => null,
            'error' => [
                'message' => 'Node execution failed',
                'code' => 'NODE_ERROR',
            ],
        ]);
    }

    public function skipped(): static
    {
        return $this->state(fn () => [
            'status' => ExecutionNodeStatus::Skipped,
            'started_at' => null,
            'finished_at' => null,
            'duration_ms' => null,
            'input_data' => null,
            'output_data' => null,
        ]);
    }
}
