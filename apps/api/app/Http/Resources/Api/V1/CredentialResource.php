<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Credential
 */
class CredentialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'is_expired' => $this->isExpired(),
            'last_used_at' => $this->last_used_at,
            'expires_at' => $this->expires_at,
            'credential_type' => new CredentialTypeResource($this->whenLoaded('credentialType')),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        $permissions = $request->attributes->get('workspace_permissions', []);

        if (in_array('credential.update', $permissions)) {
            $data['data'] = $this->getMaskedData();
        }

        return $data;
    }
}

