<?php

namespace App\Services;

use App\Models\Execution;

class TimeTravelDebuggerService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function timeline(Execution $execution): array
    {
        $events = [];

        foreach ($execution->nodes as $node) {
            $events[] = [
                'type' => 'node',
                'sequence' => $node->sequence,
                'node_id' => $node->node_id,
                'node_type' => $node->node_type,
                'status' => $node->status->value,
                'started_at' => $node->started_at,
                'finished_at' => $node->finished_at,
                'duration_ms' => $node->duration_ms,
                'input_data' => $node->input_data,
                'output_data' => $node->output_data,
                'error' => $node->error,
            ];
        }

        foreach ($execution->logs as $log) {
            $events[] = [
                'type' => 'log',
                'sequence' => PHP_INT_MAX,
                'logged_at' => $log->logged_at,
                'level' => $log->level->value,
                'message' => $log->message,
                'context' => $log->context,
                'execution_node_id' => $log->execution_node_id,
            ];
        }

        usort($events, static function (array $a, array $b): int {
            if (($a['type'] ?? '') === 'log' && ($b['type'] ?? '') === 'log') {
                return strtotime((string) ($a['logged_at'] ?? '')) <=> strtotime((string) ($b['logged_at'] ?? ''));
            }

            if (($a['type'] ?? '') === 'log') {
                return 1;
            }

            if (($b['type'] ?? '') === 'log') {
                return -1;
            }

            return (int) ($a['sequence'] ?? 0) <=> (int) ($b['sequence'] ?? 0);
        });

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotAt(Execution $execution, int $sequence): array
    {
        $nodes = $execution->nodes()->where('sequence', '<=', $sequence)->orderBy('sequence')->get();

        return [
            'execution_id' => $execution->id,
            'sequence' => $sequence,
            'status' => $execution->status->value,
            'node_states' => $nodes->map(static function ($node): array {
                return [
                    'sequence' => $node->sequence,
                    'node_id' => $node->node_id,
                    'node_type' => $node->node_type,
                    'status' => $node->status->value,
                    'input_data' => $node->input_data,
                    'output_data' => $node->output_data,
                    'error' => $node->error,
                ];
            })->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function diff(Execution $left, Execution $right): array
    {
        $leftNodes = $left->nodes()->get()->keyBy('node_id');
        $rightNodes = $right->nodes()->get()->keyBy('node_id');

        $changed = [];

        foreach ($rightNodes as $nodeId => $rightNode) {
            $leftNode = $leftNodes->get($nodeId);
            if (! $leftNode) {
                $changed[] = [
                    'node_id' => $nodeId,
                    'change' => 'added',
                    'right_status' => $rightNode->status->value,
                ];

                continue;
            }

            if (json_encode($leftNode->output_data) !== json_encode($rightNode->output_data)
                || json_encode($leftNode->error) !== json_encode($rightNode->error)
                || $leftNode->status !== $rightNode->status
            ) {
                $changed[] = [
                    'node_id' => $nodeId,
                    'change' => 'modified',
                    'left_status' => $leftNode->status->value,
                    'right_status' => $rightNode->status->value,
                    'left_output' => $leftNode->output_data,
                    'right_output' => $rightNode->output_data,
                    'left_error' => $leftNode->error,
                    'right_error' => $rightNode->error,
                ];
            }
        }

        foreach ($leftNodes as $nodeId => $leftNode) {
            if (! $rightNodes->has($nodeId)) {
                $changed[] = [
                    'node_id' => $nodeId,
                    'change' => 'removed',
                    'left_status' => $leftNode->status->value,
                ];
            }
        }

        return [
            'left_execution_id' => $left->id,
            'right_execution_id' => $right->id,
            'changed_nodes' => $changed,
        ];
    }
}
