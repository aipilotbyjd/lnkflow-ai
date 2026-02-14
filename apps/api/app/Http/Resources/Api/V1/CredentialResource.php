<?php

namespace App\Http\Resources\Api\V1;

use App\Services\WorkspacePermissionService;
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

        $user = $request->user();
        $workspace = $this->resource->workspace;

        if ($user && $workspace) {
            $permissionService = app(WorkspacePermissionService::class);

            if ($permissionService->hasPermission($user, $workspace, 'credential.update')) {
                $data['data'] = $this->getMaskedData();
            }
        }

        return $data;
    }
}
