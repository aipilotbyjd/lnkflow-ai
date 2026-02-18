<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'usage_period_id',
        'type',
        'credits',
        'description',
        'execution_id',
        'execution_node_id',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
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
     * @return BelongsTo<WorkspaceUsagePeriod, $this>
     */
    public function usagePeriod(): BelongsTo
    {
        return $this->belongsTo(WorkspaceUsagePeriod::class, 'usage_period_id');
    }

    /**
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /**
     * @return BelongsTo<ExecutionNode, $this>
     */
    public function executionNode(): BelongsTo
    {
        return $this->belongsTo(ExecutionNode::class);
    }

    public function isConsumption(): bool
    {
        return $this->credits > 0;
    }

    public function isRefund(): bool
    {
        return $this->credits < 0;
    }
}
