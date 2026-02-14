<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

class WorkspacePolicyService
{
    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    public function violations(Workspace $workspace, array $nodes): array
    {
        $policy = $workspace->policy;
        if (! $policy || ! $policy->enabled) {
            return [];
        }

        $violations = [];
        $allowedNodeTypes = collect($policy->allowed_node_types ?? [])->filter()->values();
        $blockedNodeTypes = collect($policy->blocked_node_types ?? [])->filter()->values();
        $allowedModels = collect($policy->allowed_ai_models ?? [])->filter()->values();
        $blockedModels = collect($policy->blocked_ai_models ?? [])->filter()->values();

        $estimatedCost = 0.0;
        $estimatedTokens = 0;

        foreach ($nodes as $node) {
            $nodeType = (string) ($node['type'] ?? '');

            if ($allowedNodeTypes->isNotEmpty() && ! $allowedNodeTypes->contains($nodeType)) {
                $violations[] = [
                    'code' => 'POLICY_NODE_NOT_ALLOWED',
                    'message' => "Node type {$nodeType} is not allowlisted by workspace policy.",
                    'node_id' => $node['id'] ?? null,
                    'node_type' => $nodeType,
                ];
            }

            if ($blockedNodeTypes->contains($nodeType)) {
                $violations[] = [
                    'code' => 'POLICY_NODE_BLOCKED',
                    'message' => "Node type {$nodeType} is blocked by workspace policy.",
                    'node_id' => $node['id'] ?? null,
                    'node_type' => $nodeType,
                ];
            }

            $config = $node['data']['config'] ?? $node['data'] ?? [];
            $estimatedCost += (float) ($config['estimated_cost_usd'] ?? 0);
            $estimatedTokens += (int) ($config['max_tokens'] ?? 0);

            if (str_contains($nodeType, 'ai') || $nodeType === 'ai') {
                $model = (string) ($config['model'] ?? '');
                if ($model !== '') {
                    if ($allowedModels->isNotEmpty() && ! $allowedModels->contains($model)) {
                        $violations[] = [
                            'code' => 'POLICY_MODEL_NOT_ALLOWED',
                            'message' => "AI model {$model} is not allowlisted by workspace policy.",
                            'node_id' => $node['id'] ?? null,
                            'node_type' => $nodeType,
                            'model' => $model,
                        ];
                    }

                    if ($blockedModels->contains($model)) {
                        $violations[] = [
                            'code' => 'POLICY_MODEL_BLOCKED',
                            'message' => "AI model {$model} is blocked by workspace policy.",
                            'node_id' => $node['id'] ?? null,
                            'node_type' => $nodeType,
                            'model' => $model,
                        ];
                    }
                }
            }
        }

        if ($policy->max_execution_cost_usd !== null && $estimatedCost > (float) $policy->max_execution_cost_usd) {
            $violations[] = [
                'code' => 'POLICY_COST_EXCEEDED',
                'message' => sprintf(
                    'Estimated execution cost %.4f exceeds policy limit %.4f.',
                    $estimatedCost,
                    (float) $policy->max_execution_cost_usd
                ),
                'estimated_cost_usd' => round($estimatedCost, 4),
                'policy_limit_usd' => (float) $policy->max_execution_cost_usd,
            ];
        }

        if ($policy->max_ai_tokens !== null && $estimatedTokens > (int) $policy->max_ai_tokens) {
            $violations[] = [
                'code' => 'POLICY_AI_TOKENS_EXCEEDED',
                'message' => sprintf(
                    'Estimated AI tokens %d exceed policy limit %d.',
                    $estimatedTokens,
                    (int) $policy->max_ai_tokens
                ),
                'estimated_tokens' => $estimatedTokens,
                'policy_limit_tokens' => (int) $policy->max_ai_tokens,
            ];
        }

        return $violations;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    public function assertWorkflowAllowed(Workspace $workspace, array $nodes): void
    {
        $violations = $this->violations($workspace, $nodes);

        if ($violations !== []) {
            throw ValidationException::withMessages([
                'policy' => ['Workflow violates workspace policy.'],
                'violations' => [json_encode($violations)],
            ]);
        }
    }
}
