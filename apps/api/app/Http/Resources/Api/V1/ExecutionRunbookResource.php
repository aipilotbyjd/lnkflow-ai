<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ExecutionRunbook
 */
class ExecutionRunbookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'workflow_id' => $this->workflow_id,
            'execution_id' => $this->execution_id,
            'severity' => $this->severity,
            'title' => $this->title,
            'summary' => $this->summary,
            'steps' => $this->steps,
            'tags' => $this->tags,
            'status' => $this->status,
            'acknowledged_by' => new UserResource($this->whenLoaded('acknowledgedBy')),
            'acknowledged_at' => $this->acknowledged_at,
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
        ];
    }
}
