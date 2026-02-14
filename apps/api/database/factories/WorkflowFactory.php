<?php

namespace Database\Factories;

use App\Enums\TriggerType;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Workflow>
 */
class WorkflowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'icon' => 'workflow',
            'color' => fake()->hexColor(),
            'is_active' => false,
            'is_locked' => false,
            'trigger_type' => fake()->randomElement(TriggerType::cases()),
            'trigger_config' => [],
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'trigger_manual',
                    'position' => ['x' => 100, 'y' => 100],
                    'data' => ['label' => 'Manual Trigger'],
                ],
            ],
            'edges' => [],
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
            'settings' => [
                'retry' => ['enabled' => false, 'max_attempts' => 1],
                'timeout' => ['workflow' => 3600, 'node' => 300],
            ],
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger_type' => TriggerType::Manual,
        ]);
    }

    public function webhook(): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger_type' => TriggerType::Webhook,
            'trigger_config' => [
                'path' => fake()->slug(2),
                'method' => ['POST'],
            ],
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger_type' => TriggerType::Schedule,
            'trigger_config' => [
                'cron' => '0 9 * * 1-5',
                'timezone' => 'UTC',
            ],
        ]);
    }
}
