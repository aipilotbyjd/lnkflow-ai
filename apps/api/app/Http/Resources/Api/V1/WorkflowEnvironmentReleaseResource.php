<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\WorkflowEnvironmentRelease
 */
class WorkflowEnvironmentReleaseResource extends JsonResource
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
            'from_environment' => new WorkspaceEnvironmentResource($this->whenLoaded('fromEnvironment')),
            'to_environment' => new WorkspaceEnvironmentResource($this->whenLoaded('toEnvironment')),
            'workflow_version_id' => $this->workflow_version_id,
            'triggered_by' => new UserResource($this->whenLoaded('triggeredBy')),
            'action' => $this->action,
            'commit_sha' => $this->commit_sha,
            'diff_summary' => $this->diff_summary,
            'created_at' => $this->created_at,
        ];
    }
}
