<?php

namespace App\Models;

use App\Enums\TriggerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Workflow extends Model
{
    /** @use HasFactory<\Database\Factories\WorkflowFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'created_by',
        'name',
        'description',
        'icon',
        'color',
        'is_active',
        'is_locked',
        'trigger_type',
        'trigger_config',
        'nodes',
        'edges',
        'viewport',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
            'trigger_type' => TriggerType::class,
            'trigger_config' => 'array',
            'nodes' => 'array',
            'edges' => 'array',
            'viewport' => 'array',
            'settings' => 'array',
            'last_executed_at' => 'datetime',
            'success_rate' => 'decimal:2',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsToMany<Credential, $this>
     */
    public function credentials(): BelongsToMany
    {
        return $this->belongsToMany(Credential::class, 'workflow_credentials')
            ->withPivot('node_id')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Execution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class);
    }

    /**
     * @return HasOne<Webhook, $this>
     */
    public function webhook(): HasOne
    {
        return $this->hasOne(Webhook::class);
    }

    /**
     * @return HasMany<WorkflowVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class)->orderByDesc('version_number');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'workflow_tags')->withTimestamps();
    }

    /**
     * @return HasMany<WorkflowContractSnapshot, $this>
     */
    public function contractSnapshots(): HasMany
    {
        return $this->hasMany(WorkflowContractSnapshot::class)->latest('generated_at');
    }

    /**
     * @return HasMany<ExecutionReplayPack, $this>
     */
    public function replayPacks(): HasMany
    {
        return $this->hasMany(ExecutionReplayPack::class)->latest('captured_at');
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
     * @return HasMany<WorkflowEnvironmentRelease, $this>
     */
    public function environmentReleases(): HasMany
    {
        return $this->hasMany(WorkflowEnvironmentRelease::class);
    }

    /**
     * @return HasMany<WorkflowContractTestRun, $this>
     */
    public function contractTestRuns(): HasMany
    {
        return $this->hasMany(WorkflowContractTestRun::class)->latest('executed_at');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByTriggerType($query, TriggerType $type)
    {
        return $query->where('trigger_type', $type);
    }

    public function activate(): void
    {
        $this->update(['is_active' => true]);
    }

    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    public function isScheduled(): bool
    {
        return $this->trigger_type === TriggerType::Schedule;
    }

    public function isWebhookTriggered(): bool
    {
        return $this->trigger_type === TriggerType::Webhook;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getNodeById(string $nodeId): ?array
    {
        return collect($this->nodes)->firstWhere('id', $nodeId);
    }

    public function incrementExecutionCount(bool $success): void
    {
        $this->increment('execution_count');

        $totalExecutions = $this->execution_count;
        $currentSuccessRate = (float) $this->success_rate;

        $successCount = (int) round(($currentSuccessRate / 100) * ($totalExecutions - 1));
        if ($success) {
            $successCount++;
        }

        $this->update([
            'last_executed_at' => now(),
            'success_rate' => $totalExecutions > 0 ? ($successCount / $totalExecutions) * 100 : 0,
        ]);
    }
}
