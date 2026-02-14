<?php

namespace Database\Factories;

use App\Models\Execution;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JobStatus>
 */
class JobStatusFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Str::uuid()->toString(),
            'execution_id' => Execution::factory(),
            'partition' => $this->faker->numberBetween(0, 15),
            'callback_token' => bin2hex(random_bytes(32)),
            'status' => 'pending',
            'progress' => 0,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'progress' => 100,
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'error' => ['message' => 'Test failure'],
            'completed_at' => now(),
        ]);
    }
}
