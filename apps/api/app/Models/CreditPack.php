<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditPack extends Model
{
    /** @use HasFactory<\Database\Factories\CreditPackFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'purchased_by',
        'credits_amount',
        'credits_remaining',
        'price_cents',
        'currency',
        'stripe_payment_intent_id',
        'stripe_invoice_id',
        'status',
        'purchased_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchased_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function purchaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchased_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where('credits_remaining', '>', 0)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function isUsable(): bool
    {
        return $this->status === 'active'
            && $this->credits_remaining > 0
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function consume(int $credits): int
    {
        $available = min($credits, $this->credits_remaining);
        $this->decrement('credits_remaining', $available);

        if ($this->credits_remaining <= 0) {
            $this->update(['status' => 'exhausted']);
        }

        return $available;
    }
}
