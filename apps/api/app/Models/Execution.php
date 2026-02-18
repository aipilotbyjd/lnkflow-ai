<?php

namespace App\Models;

use App\Enums\ExecutionMode;
use App\Enums\ExecutionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Execution extends Model
{
    /** @use HasFactory<\Database\Factories\ExecutionFactory> */
    use HasFactory;

    protected $fillable = [
        'workflow_id',
        'workspace_id',
        'status',
        'mode',
        'triggered_by',
        'started_at',
        'finished_at',
        'duration_ms',
        'trigger_data',
        'result_data',
        'error',
        'attempt',
        'max_attempts',
        'parent_execution_id',
        'replay_of_execution_id',
        'is_deterministic_replay',
        'estimated_cost_usd',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExecutionStatus::class,
            'mode' => ExecutionMode::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'trigger_data' => 'array',
            'result_data' => 'array',
            'error' => 'array',
            'is_deterministic_replay' => 'boolean',
            'estimated_cost_usd' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
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
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * @return BelongsTo<Execution, $this>
     */
    public function parentExecution(): BelongsTo
    {
        return $this->belongsTo(Execution::class, 'parent_execution_id');
    }

    /**
     * @return HasMany<Execution, $this>
     */
    public function retries(): HasMany
    {
        return $this->hasMany(Execution::class, 'parent_execution_id');
    }

    /**
     * @return BelongsTo<Execution, $this>
     */
    public function replayOfExecution(): BelongsTo
    {
        return $this->belongsTo(Execution::class, 'replay_of_execution_id');
    }

    /**
     * @return HasMany<Execution, $this>
     */
    public function replayExecutions(): HasMany
    {
        return $this->hasMany(Execution::class, 'replay_of_execution_id');
    }

    /**
     * @return HasMany<ExecutionNode, $this>
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(ExecutionNode::class)->orderBy('sequence');
    }

    /**
     * @return HasMany<ExecutionLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ExecutionLog::class)->orderBy('logged_at');
    }

    /**
     * @return HasMany<ConnectorCallAttempt, $this>
     */
    public function connectorAttempts(): HasMany
    {
        return $this->hasMany(ConnectorCallAttempt::class);
    }

    /**
     * @return HasMany<WorkflowApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(WorkflowApproval::class);
    }

    /**
     * @return HasOne<ExecutionReplayPack, $this>
     */
    public function replayPack(): HasOne
    {
        return $this->hasOne(ExecutionReplayPack::class);
    }

    /**
     * @return HasOne<ExecutionRunbook, $this>
     */
    public function runbook(): HasOne
    {
        return $this->hasOne(ExecutionRunbook::class);
    }

    /**
     * @return HasOne<AiFixSuggestion, $this>
     */
    public function aiFixSuggestion(): HasOne
    {
        return $this->hasOne(AiFixSuggestion::class);
    }

    public function scopeByStatus($query, ExecutionStatus $status)
    {
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            ExecutionStatus::Pending,
            ExecutionStatus::Running,
            ExecutionStatus::Waiting,
        ]);
    }

    public function scopeTerminal($query)
    {
        return $query->whereIn('status', [
            ExecutionStatus::Completed,
            ExecutionStatus::Failed,
            ExecutionStatus::Cancelled,
        ]);
    }

    public function start(): void
    {
        $this->update([
            'status' => ExecutionStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function complete(?array $resultData = null): void
    {
        $this->update([
            'status' => ExecutionStatus::Completed,
            'finished_at' => now(),
            'duration_ms' => (int) $this->started_at?->diffInMilliseconds(now()),
            'result_data' => $resultData,
        ]);
    }

    public function fail(array $error): void
    {
        $this->update([
            'status' => ExecutionStatus::Failed,
            'finished_at' => now(),
            'duration_ms' => (int) $this->started_at?->diffInMilliseconds(now()),
            'error' => $error,
        ]);
    }

    public function cancel(): void
    {
        $this->update([
            'status' => ExecutionStatus::Cancelled,
            'finished_at' => now(),
            'duration_ms' => (int) $this->started_at?->diffInMilliseconds(now()),
        ]);
    }

    public function canRetry(): bool
    {
        return $this->status === ExecutionStatus::Failed
            && $this->attempt < $this->max_attempts;
    }

    public function canCancel(): bool
    {
        return $this->status->isActive();
    }
}
