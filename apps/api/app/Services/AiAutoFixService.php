<?php

namespace App\Services;

use App\Models\AiFixSuggestion;
use App\Models\Execution;
use App\Models\WorkflowVersion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiAutoFixService
{
    public function __construct(
        private TimeTravelDebuggerService $debuggerService,
        private RunbookService $runbookService
    ) {}

    /**
     * Analyze a failed execution and generate fix suggestions.
     *
     * @return array{diagnosis: string, suggestions: array<int, array<string, mixed>>, fix_suggestion: AiFixSuggestion}
     */
    public function analyze(Execution $execution): array
    {
        $failureContext = $this->buildFailureContext($execution);
        $systemPrompt = $this->buildAnalysisPrompt();
        $userContent = $this->formatFailureForLlm($failureContext);

        $response = $this->callLlm($systemPrompt, $userContent);

        $fixSuggestion = AiFixSuggestion::query()->updateOrCreate(
            ['execution_id' => $execution->id],
            [
                'workspace_id' => $execution->workspace_id,
                'workflow_id' => $execution->workflow_id,
                'failed_node_key' => $failureContext['failed_node']['node_id'] ?? 'unknown',
                'error_message' => $failureContext['error_message'],
                'diagnosis' => $response['diagnosis'],
                'suggestions' => $response['suggestions'],
                'model_used' => $response['model_used'],
                'tokens_used' => $response['tokens_used'],
                'status' => 'pending',
            ]
        );

        $this->runbookService->ensureFailureRunbook($execution->fresh(['nodes']), $execution->error);

        return [
            'diagnosis' => $response['diagnosis'],
            'suggestions' => $response['suggestions'],
            'fix_suggestion' => $fixSuggestion,
        ];
    }

    /**
     * Apply a specific fix suggestion to create a new workflow version.
     *
     * @return array{version: WorkflowVersion, applied_patch: array<string, mixed>}
     */
    public function applyFix(AiFixSuggestion $fixSuggestion, int $suggestionIndex, ?int $userId = null): array
    {
        $suggestions = $fixSuggestion->suggestions;

        if (! isset($suggestions[$suggestionIndex])) {
            throw new \InvalidArgumentException("Suggestion index {$suggestionIndex} does not exist.");
        }

        $suggestion = $suggestions[$suggestionIndex];
        $patch = $suggestion['patch'] ?? [];
        $workflow = $fixSuggestion->workflow;

        $patchedNodes = $this->applyPatch($workflow->nodes ?? [], $patch['nodes'] ?? []);
        $patchedEdges = $this->applyPatch($workflow->edges ?? [], $patch['edges'] ?? []);

        $version = WorkflowVersion::create([
            'workflow_id' => $workflow->id,
            'version_number' => WorkflowVersion::getNextVersionNumber($workflow->id),
            'name' => $workflow->name,
            'description' => 'AI Auto-Fix: ' . ($suggestion['description'] ?? 'Applied fix suggestion'),
            'trigger_type' => $workflow->trigger_type?->value,
            'trigger_config' => $workflow->trigger_config,
            'nodes' => $patchedNodes,
            'edges' => $patchedEdges,
            'viewport' => $workflow->viewport,
            'settings' => $workflow->settings,
            'created_by' => $userId,
            'change_summary' => 'AI Auto-Fix applied: ' . ($suggestion['description'] ?? ''),
        ]);

        $workflow->update([
            'nodes' => $patchedNodes,
            'edges' => $patchedEdges,
        ]);

        $fixSuggestion->update([
            'applied_index' => $suggestionIndex,
            'status' => 'applied',
        ]);

        return [
            'version' => $version,
            'applied_patch' => $patch,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFailureContext(Execution $execution): array
    {
        $execution->load(['nodes', 'logs', 'workflow']);

        $failedNode = $execution->nodes()->where('status', 'failed')->latest('sequence')->first();
        $timeline = $this->debuggerService->timeline($execution);
        $errorMessage = $execution->error['message'] ?? $failedNode?->error['message'] ?? 'Unknown error';

        return [
            'execution_id' => $execution->id,
            'workflow_id' => $execution->workflow_id,
            'workflow_name' => $execution->workflow?->name,
            'error_message' => $errorMessage,
            'error_details' => $execution->error,
            'failed_node' => $failedNode ? [
                'node_id' => $failedNode->node_id,
                'node_type' => $failedNode->node_type,
                'node_name' => $failedNode->node_name,
                'error' => $failedNode->error,
                'input_data' => $failedNode->input_data,
                'output_data' => $failedNode->output_data,
            ] : null,
            'timeline' => $timeline,
            'workflow_nodes' => $execution->workflow?->nodes ?? [],
            'workflow_edges' => $execution->workflow?->edges ?? [],
        ];
    }

    private function buildAnalysisPrompt(): string
    {
        return <<<'PROMPT'
You are LinkFlow's workflow debugging AI. You analyze failed workflow executions and suggest fixes.

## Output Format
Respond with valid JSON:
{
  "diagnosis": "string - clear explanation of what went wrong and why",
  "suggestions": [
    {
      "description": "string - what this fix does",
      "confidence": number between 0 and 1,
      "patch": {
        "nodes": [
          {
            "action": "update|add|remove",
            "node_id": "string",
            "changes": {}
          }
        ],
        "edges": []
      }
    }
  ]
}

## Rules
1. Provide 1-3 suggestions, ordered by confidence (highest first).
2. Common fixes: data mappings, error handling, timeouts, credential references.
3. Each patch must be minimal — only change what's necessary.
4. Set confidence based on certainty the fix will resolve the issue.
5. For expired credentials or external service issues, suggest config changes with lower confidence.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $failureContext
     */
    private function formatFailureForLlm(array $failureContext): string
    {
        return json_encode([
            'workflow_name' => $failureContext['workflow_name'],
            'error' => $failureContext['error_message'],
            'error_details' => $failureContext['error_details'],
            'failed_node' => $failureContext['failed_node'],
            'workflow_nodes' => $failureContext['workflow_nodes'],
            'workflow_edges' => $failureContext['workflow_edges'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{diagnosis: string, suggestions: array<int, array<string, mixed>>, tokens_used: int, model_used: string}
     */
    private function callLlm(string $systemPrompt, string $userContent): array
    {
        $model = config('services.openai.model', 'gpt-4');
        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            return $this->placeholderAnalysis();
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => "Analyze this failed execution and suggest fixes:\n\n{$userContent}"],
                ],
                'temperature' => 0.3,
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->failed()) {
                Log::error('OpenAI auto-fix API call failed', ['status' => $response->status()]);

                return $this->placeholderAnalysis();
            }

            $data = $response->json();
            $content = json_decode($data['choices'][0]['message']['content'] ?? '{}', true);

            return [
                'diagnosis' => $content['diagnosis'] ?? 'Unable to determine root cause.',
                'suggestions' => $content['suggestions'] ?? [],
                'tokens_used' => $data['usage']['total_tokens'] ?? 0,
                'model_used' => $model,
            ];
        } catch (\Throwable $e) {
            Log::error('OpenAI auto-fix exception', ['message' => $e->getMessage()]);

            return $this->placeholderAnalysis();
        }
    }

    /**
     * @return array{diagnosis: string, suggestions: array<int, array<string, mixed>>, tokens_used: int, model_used: string}
     */
    private function placeholderAnalysis(): array
    {
        return [
            'diagnosis' => 'AI analysis unavailable. Configure your OpenAI API key for AI-powered auto-fix suggestions.',
            'suggestions' => [
                [
                    'description' => 'Review the failed node configuration and verify input data mappings.',
                    'confidence' => 0.3,
                    'patch' => ['nodes' => [], 'edges' => []],
                ],
            ],
            'tokens_used' => 0,
            'model_used' => config('services.openai.model', 'gpt-4'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $patchItems
     * @return array<int, array<string, mixed>>
     */
    private function applyPatch(array $items, array $patchItems): array
    {
        foreach ($patchItems as $patchItem) {
            $action = $patchItem['action'] ?? 'update';
            $nodeId = $patchItem['node_id'] ?? null;

            if ($action === 'add') {
                $items[] = $patchItem['changes'] ?? $patchItem;
            } elseif ($action === 'remove' && $nodeId) {
                $items = array_values(array_filter($items, fn (array $item): bool => ($item['id'] ?? '') !== $nodeId));
            } elseif ($action === 'update' && $nodeId) {
                foreach ($items as &$item) {
                    if (($item['id'] ?? '') === $nodeId) {
                        $item = array_replace_recursive($item, $patchItem['changes'] ?? []);
                    }
                }
                unset($item);
            }
        }

        return array_values($items);
    }
}
