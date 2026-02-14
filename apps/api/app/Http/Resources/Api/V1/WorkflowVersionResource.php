<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\WorkflowVersion
 */
class WorkflowVersionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'version_number' => $this->version_number,
            'name' => $this->name,
            'description' => $this->description,
            'trigger_type' => $this->trigger_type,
            'trigger_config' => $this->trigger_config,
            'nodes' => $this->nodes,
            'edges' => $this->edges,
            'viewport' => $this->viewport,
            'settings' => $this->settings,
            'change_summary' => $this->change_summary,
            'is_published' => $this->is_published,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => new UserResource($this->creator)),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
