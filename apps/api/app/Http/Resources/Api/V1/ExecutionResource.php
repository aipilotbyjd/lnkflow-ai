<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Execution
 */
class ExecutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow' => [
                'id' => $this->workflow_id,
                'name' => $this->whenLoaded('workflow', fn () => $this->workflow->name),
            ],
            'status' => $this->status,
            'mode' => $this->mode,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'duration_ms' => $this->duration_ms,
            'trigger_data' => $this->trigger_data,
            'result_data' => $this->result_data,
            'error' => $this->error,
            'attempt' => $this->attempt,
            'max_attempts' => $this->max_attempts,
            'parent_execution_id' => $this->parent_execution_id,
            'replay_of_execution_id' => $this->replay_of_execution_id,
            'is_deterministic_replay' => $this->is_deterministic_replay,
            'estimated_cost_usd' => $this->estimated_cost_usd !== null ? (float) $this->estimated_cost_usd : null,
            'replay_pack' => $this->whenLoaded('replayPack', fn () => [
                'id' => $this->replayPack->id,
                'mode' => $this->replayPack->mode,
                'deterministic_seed' => $this->replayPack->deterministic_seed,
                'captured_at' => $this->replayPack->captured_at,
            ]),
            'runbook' => $this->whenLoaded('runbook', fn () => [
                'id' => $this->runbook->id,
                'severity' => $this->runbook->severity,
                'status' => $this->runbook->status,
                'title' => $this->runbook->title,
            ]),
            'triggered_by' => new UserResource($this->whenLoaded('triggeredBy')),
            'ip_address' => $this->ip_address,
            'nodes' => ExecutionNodeResource::collection($this->whenLoaded('nodes')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
