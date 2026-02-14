<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ExecutionLog
 */
class ExecutionLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'execution_node_id' => $this->execution_node_id,
            'level' => $this->level,
            'message' => $this->message,
            'context' => $this->context,
            'logged_at' => $this->logged_at,
        ];
    }
}
