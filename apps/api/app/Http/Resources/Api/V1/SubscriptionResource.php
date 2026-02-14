<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'status' => $this->status->value,
            'trial_ends_at' => $this->trial_ends_at?->toISOString(),
            'current_period_start' => $this->current_period_start?->toISOString(),
            'current_period_end' => $this->current_period_end?->toISOString(),
            'canceled_at' => $this->canceled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
