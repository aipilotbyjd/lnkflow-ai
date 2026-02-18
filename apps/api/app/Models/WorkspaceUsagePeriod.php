<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceUsagePeriod extends Model
{
    /** @use HasFactory<\Database\Factories\WorkspaceUsagePeriodFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'subscription_id',
        'period_start',
        'period_end',
        'credits_limit',
        'credits_used',
        'credits_overage',
        'executions_total',
        'executions_succeeded',
        'executions_failed',
        'nodes_executed',
        'ai_nodes_executed',
        'data_transfer_bytes',
        'estimated_cost_usd',
        'active_workflows_count',
        'members_count',
        'is_current',
        'is_overage_billed',
        'stripe_invoice_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'is_current' => 'boolean',
            'is_overage_billed' => 'boolean',
            'estimated_cost_usd' => 'decimal:4',
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
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return HasMany<CreditTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class, 'usage_period_id');
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function creditsRemaining(): int
    {
        return max(0, $this->credits_limit - $this->credits_used);
    }

    public function creditsPercentage(): float
    {
        if ($this->credits_limit === 0) {
            return 0;
        }

        return round(($this->credits_used / $this->credits_limit) * 100, 2);
    }

    public function isExhausted(): bool
    {
        return $this->credits_used >= $this->credits_limit;
    }
}
