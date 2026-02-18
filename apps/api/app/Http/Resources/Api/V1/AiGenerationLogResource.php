<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\AiGenerationLog
 */
class AiGenerationLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'user_id' => $this->user_id,
            'prompt' => $this->prompt,
            'generated_json' => $this->generated_json,
            'model_used' => $this->model_used,
            'tokens_used' => $this->tokens_used,
            'confidence' => $this->confidence !== null ? (float) $this->confidence : null,
            'status' => $this->status,
            'workflow_id' => $this->workflow_id,
            'feedback' => $this->feedback,
            'user' => new UserResource($this->whenLoaded('user')),
            'workflow' => new WorkflowResource($this->whenLoaded('workflow')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
