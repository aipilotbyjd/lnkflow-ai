<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Str;

class Workspace extends Model
{
    /** @use HasFactory<\Database\Factories\WorkspaceFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'logo',
        'settings',
        'owner_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            if (empty($workspace->slug)) {
                $workspace->slug = Str::slug($workspace->name);

                $originalSlug = $workspace->slug;
                $count = 1;
                while (static::query()->where('slug', $workspace->slug)->exists()) {
                    $workspace->slug = $originalSlug.'-'.$count;
                    $count++;
                }
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Alias used by scoped route binding for `{user}` children.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->members();
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * @return HasMany<Workflow, $this>
     */
    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    /**
     * @return HasMany<Webhook, $this>
     */
    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    /**
     * @return HasMany<Credential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class);
    }

    /**
     * @return HasMany<Execution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class);
    }

    /**
     * @return HasMany<Variable, $this>
     */
    public function variables(): HasMany
    {
        return $this->hasMany(Variable::class);
    }

    /**
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * @return HasMany<ActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * @return HasOne<WorkspacePolicy, $this>
     */
    public function policy(): HasOne
    {
        return $this->hasOne(WorkspacePolicy::class);
    }

    /**
     * @return HasMany<WorkspaceEnvironment, $this>
     */
    public function environments(): HasMany
    {
        return $this->hasMany(WorkspaceEnvironment::class);
    }

    /**
     * @return HasMany<ConnectorCallAttempt, $this>
     */
    public function connectorAttempts(): HasMany
    {
        return $this->hasMany(ConnectorCallAttempt::class);
    }

    /**
     * @return HasMany<ConnectorMetricDaily, $this>
     */
    public function connectorMetrics(): HasMany
    {
        return $this->hasMany(ConnectorMetricDaily::class);
    }

    /**
     * @return HasMany<WorkflowApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(WorkflowApproval::class);
    }

    /**
     * @return HasMany<ExecutionRunbook, $this>
     */
    public function runbooks(): HasMany
    {
        return $this->hasMany(ExecutionRunbook::class);
    }

    /**
     * @return HasMany<AiGenerationLog, $this>
     */
    public function aiGenerationLogs(): HasMany
    {
        return $this->hasMany(AiGenerationLog::class);
    }

    /**
     * @return HasMany<AiFixSuggestion, $this>
     */
    public function aiFixSuggestions(): HasMany
    {
        return $this->hasMany(AiFixSuggestion::class);
    }

    /**
     * @return HasMany<WorkspaceUsagePeriod, $this>
     */
    public function usagePeriods(): HasMany
    {
        return $this->hasMany(WorkspaceUsagePeriod::class);
    }

    /**
     * @return HasMany<CreditPack, $this>
     */
    public function creditPacks(): HasMany
    {
        return $this->hasMany(CreditPack::class);
    }

    /**
     * @return HasMany<CreditTransaction, $this>
     */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * @return HasMany<UsageDailySnapshot, $this>
     */
    public function usageDailySnapshots(): HasMany
    {
        return $this->hasMany(UsageDailySnapshot::class);
    }

    /**
     * @return HasOneThrough<Plan, Subscription, $this>
     */
    public function plan(): HasOneThrough
    {
        return $this->hasOneThrough(Plan::class, Subscription::class, 'workspace_id', 'id', 'id', 'plan_id');
    }

    public function hasActiveSubscription(): bool
    {
        $subscription = $this->subscription;

        return $subscription && ($subscription->isActive() || $subscription->onTrial());
    }

    public function canUseFeature(string $feature): bool
    {
        $plan = $this->plan;

        return $plan && $plan->hasFeature($feature);
    }

    public function getLimit(string $limit): mixed
    {
        $plan = $this->plan;

        return $plan?->getLimit($limit);
    }
}
