<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\WorkflowApproval
 */
class WorkflowApprovalResource extends JsonResource
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
            'node_id' => $this->node_id,
            'title' => $this->title,
            'description' => $this->description,
            'payload' => $this->payload,
            'status' => $this->status,
            'due_at' => $this->due_at,
            'approved_by' => new UserResource($this->whenLoaded('approvedBy')),
            'approved_at' => $this->approved_at,
            'decision_payload' => $this->decision_payload,
            'created_at' => $this->created_at,
        ];
    }
}
