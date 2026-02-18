<?php

namespace App\Services;

use App\Models\CreditPack;
use App\Models\CreditTransaction;
use App\Models\Workspace;
use App\Models\WorkspaceUsagePeriod;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class CreditMeterService
{
    /**
     * Redis key format for credit counter.
     */
    private function redisKey(int $workspaceId): string
    {
        return "credits:used:{$workspaceId}";
    }

    private function redisLimitKey(int $workspaceId): string
    {
        return "credits:limit:{$workspaceId}";
    }

    /**
     * Increment credit usage (hot path via Redis, async persist to Postgres).
     */
    public function increment(
        int $workspaceId,
        int $credits = 1,
        string $type = 'execution',
        ?int $executionId = null,
        ?int $executionNodeId = null,
        ?string $description = null,
    ): void {
        // Increment Redis counter (atomic)
        Redis::incrby($this->redisKey($workspaceId), $credits);

        // Persist to Postgres asynchronously
        $this->recordTransaction(
            workspaceId: $workspaceId,
            type: $type,
            credits: $credits,
            executionId: $executionId,
            executionNodeId: $executionNodeId,
            description: $description,
        );
    }

    /**
     * Record a credit transaction and update the usage period.
     */
    private function recordTransaction(
        int $workspaceId,
        string $type,
        int $credits,
        ?int $executionId = null,
        ?int $executionNodeId = null,
        ?string $description = null,
    ): void {
        $period = $this->currentPeriod($workspaceId);

        if (! $period) {
            return;
        }

        CreditTransaction::create([
            'workspace_id' => $workspaceId,
            'usage_period_id' => $period->id,
            'type' => $type,
            'credits' => $credits,
            'execution_id' => $executionId,
            'execution_node_id' => $executionNodeId,
            'description' => $description,
            'created_at' => now(),
        ]);

        // Update period counters
        $increments = ['credits_used' => $credits];

        if ($type === 'execution' || $type === 'code_execution' || $type === 'webhook') {
            $increments['nodes_executed'] = 1;
        } elseif ($type === 'ai_execution') {
            $increments['ai_nodes_executed'] = 1;
            $increments['nodes_executed'] = 1;
        }

        $period->increment('credits_used', $credits);

        if (isset($increments['nodes_executed'])) {
            $period->increment('nodes_executed', $increments['nodes_executed']);
        }
        if (isset($increments['ai_nodes_executed'])) {
            $period->increment('ai_nodes_executed', $increments['ai_nodes_executed']);
        }

        // Track overage
        if ($period->credits_used > $period->credits_limit && $period->credits_limit > 0) {
            $overage = $period->credits_used - $period->credits_limit;
            $period->update(['credits_overage' => $overage]);
        }
    }

    /**
     * Get remaining credits for a workspace.
     */
    public function remaining(int $workspaceId): int
    {
        $limit = $this->getLimit($workspaceId);
        $used = $this->getUsed($workspaceId);

        // Also consider credit packs
        $packCredits = $this->availablePackCredits($workspaceId);

        return max(0, ($limit - $used) + $packCredits);
    }

    /**
     * Check if workspace has enough credits.
     */
    public function hasCredits(int $workspaceId, int $needed = 1): bool
    {
        return $this->remaining($workspaceId) >= $needed;
    }

    /**
     * Get full usage summary for the current period.
     *
     * @return array<string, mixed>
     */
    public function usage(int $workspaceId): array
    {
        $period = $this->currentPeriod($workspaceId);

        if (! $period) {
            return [
                'used' => 0,
                'limit' => 0,
                'remaining' => 0,
                'percentage' => 0,
                'overage' => 0,
                'pack_credits_remaining' => 0,
                'period_start' => null,
                'period_end' => null,
            ];
        }

        $used = $this->getUsed($workspaceId);
        $limit = $period->credits_limit;
        $packCredits = $this->availablePackCredits($workspaceId);

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, ($limit - $used) + $packCredits),
            'percentage' => $limit > 0 ? round(($used / $limit) * 100, 2) : 0,
            'overage' => max(0, $used - $limit),
            'pack_credits_remaining' => $packCredits,
            'period_start' => $period->period_start->toDateString(),
            'period_end' => $period->period_end->toDateString(),
        ];
    }

    /**
     * Get the used credits (Redis fast path, DB fallback).
     */
    public function getUsed(int $workspaceId): int
    {
        $redisValue = Redis::get($this->redisKey($workspaceId));

        if ($redisValue !== null) {
            return (int) $redisValue;
        }

        // Fallback to DB
        $period = $this->currentPeriod($workspaceId);

        if (! $period) {
            return 0;
        }

        $used = $period->credits_used;

        // Warm Redis cache
        Redis::setex($this->redisKey($workspaceId), 86400, $used);

        return $used;
    }

    /**
     * Get the credit limit for a workspace (Redis cached).
     */
    public function getLimit(int $workspaceId): int
    {
        $redisValue = Redis::get($this->redisLimitKey($workspaceId));

        if ($redisValue !== null) {
            return (int) $redisValue;
        }

        $period = $this->currentPeriod($workspaceId);
        $limit = $period?->credits_limit ?? 0;

        Redis::setex($this->redisLimitKey($workspaceId), 86400, $limit);

        return $limit;
    }

    /**
     * Get the current usage period for a workspace.
     */
    public function currentPeriod(int $workspaceId): ?WorkspaceUsagePeriod
    {
        return WorkspaceUsagePeriod::query()
            ->where('workspace_id', $workspaceId)
            ->where('is_current', true)
            ->first();
    }

    /**
     * Create a new usage period for a workspace.
     */
    public function createPeriod(
        Workspace $workspace,
        Carbon $start,
        Carbon $end,
        int $limit,
        ?int $subscriptionId = null,
    ): WorkspaceUsagePeriod {
        // Close any existing current period
        WorkspaceUsagePeriod::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_current', true)
            ->update(['is_current' => false]);

        $period = WorkspaceUsagePeriod::create([
            'workspace_id' => $workspace->id,
            'subscription_id' => $subscriptionId,
            'period_start' => $start,
            'period_end' => $end,
            'credits_limit' => $limit,
            'is_current' => true,
            'active_workflows_count' => $workspace->workflows()->where('is_active', true)->count(),
            'members_count' => $workspace->members()->count(),
        ]);

        // Reset Redis counters
        Redis::set($this->redisKey($workspace->id), 0);
        Redis::setex($this->redisLimitKey($workspace->id), 86400, $limit);

        return $period;
    }

    /**
     * Reset for a new billing period.
     */
    public function resetForNewPeriod(int $workspaceId): void
    {
        $workspace = Workspace::findOrFail($workspaceId);
        $subscription = $workspace->subscription()->with('plan')->first();

        if (! $subscription || ! $subscription->plan) {
            return;
        }

        $creditsLimit = $subscription->credits_monthly ?: ($subscription->plan->getLimit('credits_monthly') ?? 0);

        $this->createPeriod(
            workspace: $workspace,
            start: $subscription->current_period_start ?? now(),
            end: $subscription->current_period_end ?? now()->addMonth(),
            limit: $creditsLimit,
            subscriptionId: $subscription->id,
        );
    }

    /**
     * Reconcile Redis counter with actual DB transactions.
     */
    public function reconcile(int $workspaceId): void
    {
        $period = $this->currentPeriod($workspaceId);

        if (! $period) {
            return;
        }

        $actualUsed = CreditTransaction::query()
            ->where('usage_period_id', $period->id)
            ->where('credits', '>', 0)
            ->sum('credits');

        $refunds = CreditTransaction::query()
            ->where('usage_period_id', $period->id)
            ->where('credits', '<', 0)
            ->sum('credits');

        $netUsed = max(0, (int) $actualUsed + (int) $refunds);

        $period->update(['credits_used' => $netUsed]);
        Redis::set($this->redisKey($workspaceId), $netUsed);
    }

    /**
     * Get available credits from purchased credit packs.
     */
    public function availablePackCredits(int $workspaceId): int
    {
        return (int) CreditPack::query()
            ->where('workspace_id', $workspaceId)
            ->active()
            ->sum('credits_remaining');
    }

    /**
     * Consume credits from packs (FIFO — oldest first).
     */
    public function consumePackCredits(int $workspaceId, int $credits): int
    {
        $packs = CreditPack::query()
            ->where('workspace_id', $workspaceId)
            ->active()
            ->orderBy('purchased_at')
            ->get();

        $consumed = 0;

        foreach ($packs as $pack) {
            if ($consumed >= $credits) {
                break;
            }

            $consumed += $pack->consume($credits - $consumed);
        }

        return $consumed;
    }

    /**
     * Add credits from a refund or bonus.
     */
    public function addCredits(
        int $workspaceId,
        int $credits,
        string $type = 'bonus',
        ?string $description = null,
    ): void {
        $period = $this->currentPeriod($workspaceId);

        if (! $period) {
            return;
        }

        CreditTransaction::create([
            'workspace_id' => $workspaceId,
            'usage_period_id' => $period->id,
            'type' => $type,
            'credits' => -$credits, // Negative = credit added
            'description' => $description,
            'created_at' => now(),
        ]);

        // Reduce the used counter
        Redis::decrby($this->redisKey($workspaceId), $credits);
        $period->decrement('credits_used', min($credits, $period->credits_used));
    }

    /**
     * Record data transfer bytes for the current period.
     */
    public function recordDataTransfer(int $workspaceId, int $bytes): void
    {
        $period = $this->currentPeriod($workspaceId);

        if ($period) {
            $period->increment('data_transfer_bytes', $bytes);
        }
    }

    /**
     * Increment execution counters on the current period.
     */
    public function recordExecution(int $workspaceId, bool $succeeded): void
    {
        $period = $this->currentPeriod($workspaceId);

        if (! $period) {
            return;
        }

        $period->increment('executions_total');

        if ($succeeded) {
            $period->increment('executions_succeeded');
        } else {
            $period->increment('executions_failed');
        }
    }

    /**
     * Get usage percentage for threshold checks.
     */
    public function usagePercentage(int $workspaceId): float
    {
        $limit = $this->getLimit($workspaceId);

        if ($limit <= 0) {
            return 0;
        }

        return round(($this->getUsed($workspaceId) / $limit) * 100, 2);
    }
}
