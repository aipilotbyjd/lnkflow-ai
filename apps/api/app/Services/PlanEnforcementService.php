<?php

namespace App\Services;

use App\Exceptions\FeatureNotAvailableException;
use App\Exceptions\PlanLimitException;
use App\Exceptions\QuotaExceededException;
use App\Models\Workspace;
use Illuminate\Support\Facades\Redis;

class PlanEnforcementService
{
    /**
     * Map features to the minimum plan slug that includes them.
     *
     * @var array<string, string>
     */
    private const FEATURE_PLAN_MAP = [
        'webhooks' => 'starter',
        'api_access' => 'starter',
        'import_export' => 'starter',
        'custom_variables' => 'pro',
        'sub_workflows' => 'pro',
        'parallel_execution' => 'pro',
        'full_text_log_search' => 'pro',
        'deterministic_replay' => 'pro',
        'ai_autofix' => 'pro',
        'ai_generation' => 'pro',
        'approval_workflows' => 'teams',
        'workspace_policies' => 'teams',
        'environments' => 'teams',
        'connector_metrics' => 'teams',
        'execution_runbooks' => 'teams',
        'analytics_dashboard' => 'pro',
        'audit_logs' => 'enterprise',
        'sso_saml' => 'enterprise',
        'custom_node_types' => 'enterprise',
    ];

    public function __construct(
        private CreditMeterService $creditMeter,
    ) {}

    /**
     * Check if the workspace has enough credits to proceed.
     *
     * @throws PlanLimitException
     */
    public function checkCredits(Workspace $workspace, int $needed = 1): void
    {
        $usage = $this->creditMeter->usage($workspace->id);

        if ($usage['limit'] === -1) {
            return; // Explicitly unlimited (Enterprise)
        }

        if ($usage['limit'] === 0 && $usage['period_start'] === null) {
            // No usage period exists — fail closed to prevent unlimited usage
            throw new PlanLimitException(
                limitType: 'credits',
                currentUsage: 0,
                limit: 0,
                message: 'No active billing period. Please contact support.',
            );
        }

        // If plan supports overage, allow even if exhausted
        if ($this->canOverage($workspace)) {
            return;
        }

        if ($usage['remaining'] < $needed) {
            throw new PlanLimitException(
                limitType: 'credits',
                currentUsage: $usage['used'],
                limit: $usage['limit'],
                message: 'Monthly credit limit reached. Upgrade your plan or purchase a credit pack to continue.',
            );
        }
    }

    /**
     * Check if a feature is available on the workspace's plan.
     *
     * @throws FeatureNotAvailableException
     */
    public function checkFeature(Workspace $workspace, string $feature): void
    {
        if ($workspace->canUseFeature($feature)) {
            return;
        }

        $requiredPlan = self::FEATURE_PLAN_MAP[$feature] ?? 'pro';

        throw new FeatureNotAvailableException(
            feature: $feature,
            requiredPlan: ucfirst($requiredPlan),
        );
    }

    /**
     * Check active workflow count against plan limit.
     *
     * @throws QuotaExceededException
     */
    public function checkActiveWorkflows(Workspace $workspace): void
    {
        $limit = $workspace->getLimit('active_workflows');

        if ($limit === null || $limit === -1) {
            return; // Unlimited
        }

        $current = $workspace->workflows()->where('is_active', true)->count();

        if ($current >= $limit) {
            throw new QuotaExceededException(
                resource: 'active_workflows',
                currentCount: $current,
                maxAllowed: $limit,
            );
        }
    }

    /**
     * Check team member count against plan limit.
     *
     * @throws QuotaExceededException
     */
    public function checkMembers(Workspace $workspace): void
    {
        $limit = $workspace->getLimit('members');

        if ($limit === null || $limit === -1) {
            return;
        }

        $current = $workspace->members()->count();

        if ($current >= $limit) {
            throw new QuotaExceededException(
                resource: 'members',
                currentCount: $current,
                maxAllowed: $limit,
            );
        }
    }

    /**
     * Check webhook count against plan limit.
     *
     * @throws QuotaExceededException
     */
    public function checkWebhooks(Workspace $workspace): void
    {
        $this->checkFeature($workspace, 'webhooks');

        $limit = $workspace->getLimit('webhooks');

        if ($limit === null || $limit === -1) {
            return;
        }

        $current = $workspace->webhooks()->count();

        if ($current >= $limit) {
            throw new QuotaExceededException(
                resource: 'webhooks',
                currentCount: $current,
                maxAllowed: $limit,
            );
        }
    }

    /**
     * Check credential count against plan limit.
     *
     * @throws QuotaExceededException
     */
    public function checkCredentials(Workspace $workspace): void
    {
        $limit = $workspace->getLimit('credentials');

        if ($limit === null || $limit === -1) {
            return;
        }

        $current = $workspace->credentials()->count();

        if ($current >= $limit) {
            throw new QuotaExceededException(
                resource: 'credentials',
                currentCount: $current,
                maxAllowed: $limit,
            );
        }
    }

    /**
     * Check variable count against plan limit.
     *
     * @throws QuotaExceededException
     */
    public function checkVariables(Workspace $workspace): void
    {
        $this->checkFeature($workspace, 'custom_variables');

        $limit = $workspace->getLimit('variables');

        if ($limit === null || $limit === -1) {
            return;
        }

        $current = $workspace->variables()->count();

        if ($current >= $limit) {
            throw new QuotaExceededException(
                resource: 'variables',
                currentCount: $current,
                maxAllowed: $limit,
            );
        }
    }

    /**
     * Check file size against plan limit.
     *
     * @throws PlanLimitException
     */
    public function checkFileSize(Workspace $workspace, int $bytes): void
    {
        $limitMb = $workspace->getLimit('max_file_size_mb');

        if ($limitMb === null || $limitMb === -1) {
            return;
        }

        $limitBytes = $limitMb * 1024 * 1024;

        if ($bytes > $limitBytes) {
            throw new PlanLimitException(
                limitType: 'file_size',
                currentUsage: (int) round($bytes / 1024 / 1024),
                limit: $limitMb,
                message: "File size exceeds plan limit of {$limitMb}MB.",
            );
        }
    }

    /**
     * Check data transfer against plan limit.
     *
     * @throws PlanLimitException
     */
    public function checkDataTransfer(Workspace $workspace, int $additionalBytes = 0): void
    {
        $limitGb = $workspace->getLimit('data_transfer_gb');

        if ($limitGb === null || $limitGb === -1) {
            return;
        }

        $period = $this->creditMeter->currentPeriod($workspace->id);

        if (! $period) {
            return;
        }

        $limitBytes = (int) ($limitGb * 1024 * 1024 * 1024);
        $currentBytes = $period->data_transfer_bytes + $additionalBytes;

        if ($currentBytes > $limitBytes) {
            throw new PlanLimitException(
                limitType: 'data_transfer',
                currentUsage: (int) round($currentBytes / 1024 / 1024 / 1024, 2),
                limit: (int) $limitGb,
                message: "Data transfer limit of {$limitGb}GB exceeded.",
            );
        }
    }

    /**
     * Check schedule interval against plan minimum.
     *
     * @throws PlanLimitException
     */
    public function checkScheduleInterval(Workspace $workspace, int $intervalMinutes): void
    {
        $minInterval = $workspace->getLimit('min_schedule_interval');

        if ($minInterval === null) {
            return;
        }

        if ($intervalMinutes < $minInterval) {
            throw new PlanLimitException(
                limitType: 'schedule_interval',
                currentUsage: $intervalMinutes,
                limit: $minInterval,
                message: "Minimum schedule interval for your plan is {$minInterval} minutes.",
            );
        }
    }

    /**
     * Check concurrent execution count.
     *
     * @throws PlanLimitException
     */
    public function checkConcurrentExecutions(Workspace $workspace): void
    {
        $limit = $workspace->getLimit('concurrent_executions');

        if ($limit === null || $limit === -1) {
            return;
        }

        $redisKey = "concurrent_exec:{$workspace->id}";
        $current = (int) Redis::get($redisKey);

        if ($current >= $limit) {
            throw new PlanLimitException(
                limitType: 'concurrent_executions',
                currentUsage: $current,
                limit: $limit,
                message: "Maximum {$limit} concurrent executions allowed on your plan.",
            );
        }
    }

    /**
     * Increment/decrement concurrent execution counter.
     */
    public function trackConcurrentExecution(int $workspaceId, bool $start): void
    {
        $redisKey = "concurrent_exec:{$workspaceId}";

        if ($start) {
            Redis::incr($redisKey);
            Redis::expire($redisKey, 7200); // 2 hour safety TTL
        } else {
            Redis::decr($redisKey);
            // Floor at 0
            if ((int) Redis::get($redisKey) < 0) {
                Redis::set($redisKey, 0);
            }
        }
    }

    /**
     * Get the API rate limit per minute for the workspace's plan.
     */
    public function getRateLimitPerMinute(Workspace $workspace): int
    {
        return (int) ($workspace->getLimit('api_rate_limit_per_min') ?? 0);
    }

    /**
     * Get execution priority queue based on plan.
     */
    public function getExecutionPriority(Workspace $workspace): string
    {
        $priority = $workspace->plan?->hasFeature('priority_execution');

        if ($priority === 'priority') {
            return 'priority';
        }

        if ($priority === true || $priority === 'high') {
            return 'high';
        }

        return 'default';
    }

    /**
     * Get max execution time in seconds for the workspace's plan.
     */
    public function getMaxExecutionTime(Workspace $workspace): int
    {
        return (int) ($workspace->getLimit('max_execution_time') ?? 300);
    }

    /**
     * Get log retention days for the workspace's plan.
     */
    public function getLogRetentionDays(Workspace $workspace): int
    {
        return (int) ($workspace->getLimit('execution_log_days') ?? 3);
    }

    /**
     * Whether the workspace's plan supports overage (soft limit).
     */
    public function canOverage(Workspace $workspace): bool
    {
        return (bool) ($workspace->plan?->hasFeature('overage_protection') ?? false);
    }

    /**
     * Get all plan limits as an array (for passing to Engine).
     *
     * @return array<string, mixed>
     */
    public function getPlanContext(Workspace $workspace): array
    {
        $plan = $workspace->plan;

        return [
            'max_execution_time_seconds' => $this->getMaxExecutionTime($workspace),
            'max_file_size_bytes' => ($workspace->getLimit('max_file_size_mb') ?? 5) * 1024 * 1024,
            'max_concurrent_executions' => $workspace->getLimit('concurrent_executions') ?? 1,
            'execution_priority' => $this->getExecutionPriority($workspace),
            'credits_remaining' => $this->creditMeter->remaining($workspace->id),
            'max_data_transfer_bytes' => (int) (($workspace->getLimit('data_transfer_gb') ?? 0.5) * 1024 * 1024 * 1024),
            'features' => [
                'parallel_execution' => (bool) $plan?->hasFeature('parallel_execution'),
                'sub_workflows' => (bool) $plan?->hasFeature('sub_workflows'),
            ],
        ];
    }
}
