<?php

namespace App\Services;

use App\Models\AiGenerationLog;
use App\Models\Credential;
use App\Models\Node;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiWorkflowGeneratorService
{
    /**
     * Generate a workflow from a natural language prompt.
     *
     * @param  array<int, string>  $credentialIds
     * @return array{workflow: array<string, mixed>, explanation: string, confidence: float, log: AiGenerationLog}
     */
    public function generate(Workspace $workspace, User $user, string $prompt, array $credentialIds = [], bool $dryRun = false): array
    {
        $nodeCatalog = $this->buildNodeCatalog();
        $credentials = $this->getCredentialContext($workspace, $credentialIds);
        $systemPrompt = $this->buildSystemPrompt($nodeCatalog, $credentials);

        $response = $this->callLlm($systemPrompt, $prompt);

        $log = AiGenerationLog::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'prompt' => $prompt,
            'generated_json' => $response['workflow'],
            'model_used' => $response['model_used'],
            'tokens_used' => $response['tokens_used'],
            'confidence' => $response['confidence'],
            'status' => 'draft',
        ]);

        return [
            'workflow' => $response['workflow'],
            'explanation' => $response['explanation'],
            'confidence' => $response['confidence'],
            'log' => $log,
        ];
    }

    /**
     * Refine a previously generated workflow based on feedback.
     *
     * @return array{workflow: array<string, mixed>, changes: array<int, string>, log: AiGenerationLog}
     */
    public function refine(Workspace $workspace, User $user, AiGenerationLog $generationLog, string $feedback): array
    {
        $nodeCatalog = $this->buildNodeCatalog();
        $systemPrompt = $this->buildRefinementPrompt($nodeCatalog, $generationLog->generated_json);

        $response = $this->callLlmForRefinement($systemPrompt, $feedback, $generationLog->generated_json);

        $generationLog->update([
            'feedback' => $feedback,
            'status' => 'refined',
        ]);

        $newLog = AiGenerationLog::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'prompt' => $generationLog->prompt . "\n\nRefinement: " . $feedback,
            'generated_json' => $response['workflow'],
            'model_used' => $response['model_used'],
            'tokens_used' => $response['tokens_used'],
            'confidence' => $generationLog->confidence,
            'status' => 'draft',
        ]);

        return [
            'workflow' => $response['workflow'],
            'changes' => $response['changes'],
            'log' => $newLog,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildNodeCatalog(): array
    {
        return Node::query()
            ->where('is_active', true)
            ->with('category')
            ->get()
            ->map(fn (Node $node): array => [
                'type' => $node->type,
                'name' => $node->name,
                'description' => $node->description,
                'category' => $node->category?->name,
                'node_kind' => $node->node_kind->value,
                'config_schema' => $node->config_schema,
                'input_schema' => $node->input_schema,
                'output_schema' => $node->output_schema,
                'credential_type' => $node->credential_type,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $credentialIds
     * @return array<int, array<string, mixed>>
     */
    private function getCredentialContext(Workspace $workspace, array $credentialIds): array
    {
        $query = $workspace->credentials();

        if (! empty($credentialIds)) {
            $query->whereIn('id', $credentialIds);
        }

        return $query->get()
            ->map(fn (Credential $credential): array => [
                'id' => $credential->id,
                'name' => $credential->name,
                'type' => $credential->type,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodeCatalog
     * @param  array<int, array<string, mixed>>  $credentials
     */
    private function buildSystemPrompt(array $nodeCatalog, array $credentials): string
    {
        $catalogJson = json_encode($nodeCatalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $credentialsJson = json_encode($credentials, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
You are LinkFlow's workflow generation AI. Your task is to generate valid workflow definitions from natural language descriptions.

## Available Node Types
{$catalogJson}

## Available Credentials
{$credentialsJson}

## Output Format
You MUST respond with a valid JSON object containing:
{
  "workflow": {
    "name": "string - descriptive workflow name",
    "description": "string - what this workflow does",
    "trigger_type": "manual|schedule|webhook",
    "trigger_config": {},
    "nodes": [
      {
        "id": "string - unique node id like trigger_1, http_1, etc",
        "type": "string - must be from the available node types above",
        "position": {"x": number, "y": number},
        "data": {
          "label": "string - human readable label",
          "config": {}
        }
      }
    ],
    "edges": [
      {
        "id": "string - unique edge id like edge_1",
        "source": "string - source node id",
        "target": "string - target node id"
      }
    ]
  },
  "explanation": "string - explain what the workflow does and how",
  "confidence": number between 0 and 1
}

## Rules
1. Every workflow must start with a trigger node.
2. Node IDs must be unique and descriptive (e.g., trigger_1, http_request_1, slack_1).
3. Edge connections must form a valid DAG (no cycles).
4. Position nodes left-to-right, starting at x=100, y=200, with x increments of 250.
5. Only use node types from the catalog above.
6. If a node requires credentials, reference them in the config.
7. Set confidence lower if the request is ambiguous.
PROMPT;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodeCatalog
     * @param  array<string, mixed>  $currentWorkflow
     */
    private function buildRefinementPrompt(array $nodeCatalog, array $currentWorkflow): string
    {
        $catalogJson = json_encode($nodeCatalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $workflowJson = json_encode($currentWorkflow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
You are LinkFlow's workflow refinement AI. Improve the workflow based on user feedback.

## Available Node Types
{$catalogJson}

## Current Workflow
{$workflowJson}

## Output Format
Respond with valid JSON:
{
  "workflow": { ... the updated workflow definition ... },
  "changes": ["string - description of each change made"]
}

## Rules
1. Only modify what the user requests.
2. Maintain valid node IDs and edge connections.
3. Keep the same output structure as the original workflow.
PROMPT;
    }

    /**
     * @return array{workflow: array<string, mixed>, explanation: string, confidence: float, tokens_used: int, model_used: string}
     */
    private function callLlm(string $systemPrompt, string $userPrompt): array
    {
        $model = config('services.openai.model', 'gpt-4');
        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            return $this->placeholderResponse($userPrompt);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.7,
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->failed()) {
                Log::error('OpenAI API call failed', ['status' => $response->status(), 'body' => $response->body()]);

                return $this->placeholderResponse($userPrompt);
            }

            $data = $response->json();
            $content = json_decode($data['choices'][0]['message']['content'] ?? '{}', true);
            $tokensUsed = $data['usage']['total_tokens'] ?? 0;

            return [
                'workflow' => $content['workflow'] ?? $this->buildPlaceholderWorkflow($userPrompt),
                'explanation' => $content['explanation'] ?? 'Generated workflow based on your description.',
                'confidence' => (float) ($content['confidence'] ?? 0.5),
                'tokens_used' => $tokensUsed,
                'model_used' => $model,
            ];
        } catch (\Throwable $e) {
            Log::error('OpenAI API call exception', ['message' => $e->getMessage()]);

            return $this->placeholderResponse($userPrompt);
        }
    }

    /**
     * @param  array<string, mixed>  $currentWorkflow
     * @return array{workflow: array<string, mixed>, changes: array<int, string>, tokens_used: int, model_used: string}
     */
    private function callLlmForRefinement(string $systemPrompt, string $feedback, array $currentWorkflow): array
    {
        $model = config('services.openai.model', 'gpt-4');
        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            return [
                'workflow' => $currentWorkflow,
                'changes' => ['No changes applied — AI service not configured.'],
                'tokens_used' => 0,
                'model_used' => $model,
            ];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => "Please refine the workflow based on this feedback: {$feedback}"],
                ],
                'temperature' => 0.7,
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->failed()) {
                return [
                    'workflow' => $currentWorkflow,
                    'changes' => ['Refinement failed — AI service error.'],
                    'tokens_used' => 0,
                    'model_used' => $model,
                ];
            }

            $data = $response->json();
            $content = json_decode($data['choices'][0]['message']['content'] ?? '{}', true);

            return [
                'workflow' => $content['workflow'] ?? $currentWorkflow,
                'changes' => $content['changes'] ?? ['Workflow refined based on feedback.'],
                'tokens_used' => $data['usage']['total_tokens'] ?? 0,
                'model_used' => $model,
            ];
        } catch (\Throwable $e) {
            Log::error('OpenAI refinement exception', ['message' => $e->getMessage()]);

            return [
                'workflow' => $currentWorkflow,
                'changes' => ['Refinement failed: ' . $e->getMessage()],
                'tokens_used' => 0,
                'model_used' => $model,
            ];
        }
    }

    /**
     * @return array{workflow: array<string, mixed>, explanation: string, confidence: float, tokens_used: int, model_used: string}
     */
    private function placeholderResponse(string $prompt): array
    {
        return [
            'workflow' => $this->buildPlaceholderWorkflow($prompt),
            'explanation' => 'Generated a basic workflow structure. Configure your OpenAI API key for AI-powered generation.',
            'confidence' => 0.3,
            'tokens_used' => 0,
            'model_used' => config('services.openai.model', 'gpt-4'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlaceholderWorkflow(string $prompt): array
    {
        return [
            'name' => 'AI Generated Workflow',
            'description' => "Workflow generated from: {$prompt}",
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
        ];
    }
}
