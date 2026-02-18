<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CreditPack;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CreditPack
 */
class CreditPackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'credits_amount' => $this->credits_amount,
            'credits_remaining' => $this->credits_remaining,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'status' => $this->status,
            'purchased_at' => $this->purchased_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
        ];
    }
}
