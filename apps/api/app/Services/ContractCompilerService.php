<?php

namespace App\Services;

use App\Models\Node;
use App\Models\Workflow;
use App\Models\WorkflowContractSnapshot;

class ContractCompilerService
{
    /**
     * @param  array<int, array<string, mixed>>|null  $nodes
     * @param  array<int, array<string, mixed>>|null  $edges
     * @return array{snapshot: WorkflowContractSnapshot, edge_contracts: array<int, array<string, mixed>>, issues: array<int, array<string, mixed>>, status: string}
     */
    public function validateAndSnapshot(Workflow $workflow, ?array $nodes = null, ?array $edges = null, bool $strict = false): array
    {
        $graphNodes = array_values($nodes ?? $workflow->nodes ?? []);
        $graphEdges = array_values($edges ?? $workflow->edges ?? []);

        $graphHash = $this->graphHash($graphNodes, $graphEdges);

        $existing = WorkflowContractSnapshot::query()
            ->where('workflow_id', $workflow->id)
            ->where('graph_hash', $graphHash)
            ->first();

        if ($existing) {
            return [
                'snapshot' => $existing,
                'edge_contracts' => $existing->contracts['edge_contracts'] ?? [],
                'issues' => $existing->issues ?? [],
                'status' => $existing->status,
            ];
        }

        $nodeTypes = collect($graphNodes)->pluck('type')->filter()->unique()->values()->all();
        $nodeDefinitions = Node::query()
            ->whereIn('type', $nodeTypes)
            ->get()
            ->keyBy('type');

        $nodeMap = [];
        foreach ($graphNodes as $node) {
            if (! isset($node['id']) || ! isset($node['type'])) {
                continue;
            }

            $definition = $nodeDefinitions->get($node['type']);
            $nodeMap[$node['id']] = [
                'id' => $node['id'],
                'type' => $node['type'],
                'label' => $node['data']['label'] ?? $node['data']['name'] ?? $node['id'],
                'output_schema' => $definition?->output_schema,
                'input_schema' => $definition?->input_schema,
                'config_schema' => $definition?->config_schema,
            ];
        }

        $issues = [];
        $edgeContracts = [];

        foreach ($graphEdges as $edge) {
            $sourceId = $edge['source'] ?? null;
            $targetId = $edge['target'] ?? null;

            if (! $sourceId || ! $targetId) {
                $issues[] = [
                    'code' => 'UNKNOWN_SOURCE_PATH',
                    'severity' => 'warning',
                    'message' => 'Edge has missing source or target.',
                    'edge_id' => $edge['id'] ?? null,
                ];

                continue;
            }

            $sourceNode = $nodeMap[$sourceId] ?? null;
            $targetNode = $nodeMap[$targetId] ?? null;

            if (! $sourceNode || ! $targetNode) {
                $issues[] = [
                    'code' => 'UNKNOWN_SOURCE_PATH',
                    'severity' => 'warning',
                    'message' => 'Edge references unknown nodes.',
                    'edge_id' => $edge['id'] ?? null,
                ];

                continue;
            }

            $sourceOutputSchema = $sourceNode['output_schema'] ?? ['type' => 'object'];
            $targetInputSchema = $targetNode['input_schema']
                ?? ($targetNode['config_schema']['input'] ?? ['type' => 'object']);

            $typeMismatch = $this->hasTypeMismatch($sourceOutputSchema, $targetInputSchema);
            $missingRequired = $this->missingRequiredFields($sourceOutputSchema, $targetInputSchema);

            $edgeStatus = 'valid';
            if ($typeMismatch) {
                $edgeStatus = 'invalid';
                $issues[] = [
                    'code' => 'TYPE_MISMATCH',
                    'severity' => 'error',
                    'message' => sprintf('Type mismatch between %s and %s.', $sourceNode['label'], $targetNode['label']),
                    'edge_id' => $edge['id'] ?? null,
                    'source_node_id' => $sourceId,
                    'target_node_id' => $targetId,
                ];
            }

            if (! empty($missingRequired)) {
                $edgeStatus = $edgeStatus === 'invalid' ? 'invalid' : 'warning';
                $issues[] = [
                    'code' => 'MISSING_REQUIRED_FIELD',
                    'severity' => $strict ? 'error' : 'warning',
                    'message' => sprintf(
                        'Missing required fields for %s: %s',
                        $targetNode['label'],
                        implode(', ', $missingRequired)
                    ),
                    'edge_id' => $edge['id'] ?? null,
                    'target_node_id' => $targetId,
                    'fields' => $missingRequired,
                ];
            }

            if ($strict && ! empty($missingRequired)) {
                $edgeStatus = 'invalid';
            }

            $edgeContracts[] = [
                'edge_id' => $edge['id'] ?? null,
                'source_node_id' => $sourceId,
                'target_node_id' => $targetId,
                'source_output_schema' => $sourceOutputSchema,
                'target_input_schema' => $targetInputSchema,
                'status' => $edgeStatus,
            ];
        }

        $status = $this->calculateStatus($issues, $strict);

        $snapshot = WorkflowContractSnapshot::query()->create([
            'workflow_id' => $workflow->id,
            'workflow_version_id' => $workflow->current_version_id,
            'graph_hash' => $graphHash,
            'status' => $status,
            'contracts' => [
                'node_count' => count($graphNodes),
                'edge_count' => count($graphEdges),
                'edge_contracts' => $edgeContracts,
            ],
            'issues' => $issues,
            'generated_at' => now(),
        ]);

        return [
            'snapshot' => $snapshot,
            'edge_contracts' => $edgeContracts,
            'issues' => $issues,
            'status' => $status,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     */
    public function graphHash(array $nodes, array $edges): string
    {
        return hash('sha256', json_encode([
            'nodes' => $nodes,
            'edges' => $edges,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>|null  $sourceSchema
     * @param  array<string, mixed>|null  $targetSchema
     */
    private function hasTypeMismatch(?array $sourceSchema, ?array $targetSchema): bool
    {
        $sourceType = $sourceSchema['type'] ?? 'object';
        $targetType = $targetSchema['type'] ?? 'object';

        if ($targetType === 'any' || $sourceType === 'any') {
            return false;
        }

        return $sourceType !== $targetType;
    }

    /**
     * @param  array<string, mixed>|null  $sourceSchema
     * @param  array<string, mixed>|null  $targetSchema
     * @return array<int, string>
     */
    private function missingRequiredFields(?array $sourceSchema, ?array $targetSchema): array
    {
        $required = $targetSchema['required'] ?? [];
        if (! is_array($required) || $required === []) {
            return [];
        }

        $sourceProps = array_keys($sourceSchema['properties'] ?? []);

        return array_values(array_filter($required, static fn ($field): bool => ! in_array($field, $sourceProps, true)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $issues
     */
    private function calculateStatus(array $issues, bool $strict): string
    {
        $hasError = collect($issues)->contains(static fn (array $issue): bool => ($issue['severity'] ?? '') === 'error');
        if ($hasError) {
            return 'invalid';
        }

        if ($strict && count($issues) > 0) {
            return 'invalid';
        }

        return count($issues) > 0 ? 'warning' : 'valid';
    }
}
