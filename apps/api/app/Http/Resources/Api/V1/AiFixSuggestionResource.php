<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\AiFixSuggestion
 */
class AiFixSuggestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'execution_id' => $this->execution_id,
            'workflow_id' => $this->workflow_id,
            'failed_node_key' => $this->failed_node_key,
            'error_message' => $this->error_message,
            'diagnosis' => $this->diagnosis,
            'suggestions' => $this->suggestions,
            'applied_index' => $this->applied_index,
            'model_used' => $this->model_used,
            'tokens_used' => $this->tokens_used,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
