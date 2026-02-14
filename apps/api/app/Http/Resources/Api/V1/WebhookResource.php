<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Webhook
 */
class WebhookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'uuid' => $this->uuid,
            'url' => $this->getUrl(),
            'path' => $this->path,
            'methods' => $this->methods,
            'is_active' => $this->is_active,
            'auth_type' => $this->auth_type,
            'rate_limit' => $this->rate_limit,
            'response_mode' => $this->response_mode,
            'response_status' => $this->response_status,
            'response_body' => $this->response_body,
            'call_count' => $this->call_count,
            'last_called_at' => $this->last_called_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
