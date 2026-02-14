<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\WorkspacePolicy
 */
class WorkspacePolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'enabled' => $this->enabled,
            'allowed_node_types' => $this->allowed_node_types ?? [],
            'blocked_node_types' => $this->blocked_node_types ?? [],
            'allowed_ai_models' => $this->allowed_ai_models ?? [],
            'blocked_ai_models' => $this->blocked_ai_models ?? [],
            'max_execution_cost_usd' => $this->max_execution_cost_usd !== null ? (float) $this->max_execution_cost_usd : null,
            'max_ai_tokens' => $this->max_ai_tokens,
            'redaction_rules' => $this->redaction_rules ?? [],
            'updated_at' => $this->updated_at,
        ];
    }
}
