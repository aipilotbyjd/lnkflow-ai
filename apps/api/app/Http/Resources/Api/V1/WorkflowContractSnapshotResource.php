<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\WorkflowContractSnapshot
 */
class WorkflowContractSnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'workflow_version_id' => $this->workflow_version_id,
            'graph_hash' => $this->graph_hash,
            'status' => $this->status,
            'contracts' => $this->contracts,
            'issues' => $this->issues,
            'generated_at' => $this->generated_at,
            'created_at' => $this->created_at,
        ];
    }
}
