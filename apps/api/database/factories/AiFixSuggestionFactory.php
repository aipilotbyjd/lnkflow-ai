<?php

namespace Database\Factories;

use App\Models\AiFixSuggestion;
use App\Models\Execution;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiFixSuggestion>
 */
class AiFixSuggestionFactory extends Factory
{
    protected $model = AiFixSuggestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'execution_id' => Execution::factory(),
            'workflow_id' => Workflow::factory(),
            'failed_node_key' => 'http_request_1',
            'error_message' => $this->faker->sentence(),
            'diagnosis' => $this->faker->paragraph(),
            'suggestions' => [
                [
                    'description' => 'Fix the HTTP request configuration',
                    'confidence' => 0.85,
                    'patch' => ['nodes' => [], 'edges' => []],
                ],
            ],
            'model_used' => 'gpt-4',
            'tokens_used' => $this->faker->numberBetween(200, 3000),
            'status' => 'pending',
        ];
    }
}
