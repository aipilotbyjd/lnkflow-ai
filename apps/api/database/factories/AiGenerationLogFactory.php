<?php

namespace Database\Factories;

use App\Models\AiGenerationLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiGenerationLog>
 */
class AiGenerationLogFactory extends Factory
{
    protected $model = AiGenerationLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'prompt' => $this->faker->sentence(10),
            'generated_json' => [
                'name' => 'Generated Workflow',
                'description' => $this->faker->sentence(),
                'trigger_type' => 'manual',
                'trigger_config' => [],
                'nodes' => [
                    [
                        'id' => 'trigger_1',
                        'type' => 'trigger_manual',
                        'position' => ['x' => 100, 'y' => 200],
                        'data' => ['label' => 'Manual Trigger'],
                    ],
                ],
                'edges' => [],
            ],
            'model_used' => 'gpt-4',
            'tokens_used' => $this->faker->numberBetween(100, 5000),
            'confidence' => $this->faker->randomFloat(2, 0.5, 1.0),
            'status' => 'draft',
        ];
    }
}
