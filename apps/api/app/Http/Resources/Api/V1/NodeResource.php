<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Node
 */
class NodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'color' => $this->color,
            'node_kind' => $this->node_kind->value,
            'config_schema' => $this->config_schema,
            'input_schema' => $this->input_schema,
            'output_schema' => $this->output_schema,
            'credential_type' => $this->credential_type,
            'cost_hint_usd' => $this->cost_hint_usd !== null ? (float) $this->cost_hint_usd : null,
            'latency_hint_ms' => $this->latency_hint_ms,
            'is_premium' => $this->is_premium,
            'docs_url' => $this->docs_url,
            'category' => new NodeCategoryResource($this->whenLoaded('category')),
        ];
    }
}
