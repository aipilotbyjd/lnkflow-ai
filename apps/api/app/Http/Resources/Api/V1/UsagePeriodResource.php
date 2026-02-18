<?php

namespace App\Http\Resources\Api\V1;

use App\Models\WorkspaceUsagePeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkspaceUsagePeriod
 */
class UsagePeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'credits_limit' => $this->credits_limit,
            'credits_used' => $this->credits_used,
            'credits_remaining' => $this->creditsRemaining(),
            'credits_percentage' => $this->creditsPercentage(),
            'credits_overage' => $this->credits_overage,
            'executions_total' => $this->executions_total,
            'executions_succeeded' => $this->executions_succeeded,
            'executions_failed' => $this->executions_failed,
            'nodes_executed' => $this->nodes_executed,
            'data_transfer_bytes' => $this->data_transfer_bytes,
            'is_current' => $this->is_current,
        ];
    }
}
