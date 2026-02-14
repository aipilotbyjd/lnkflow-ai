<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorCallAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'execution_id',
        'execution_node_id',
        'workspace_id',
        'workflow_id',
        'connector_key',
        'connector_operation',
        'provider',
        'attempt_no',
        'is_retry',
        'status',
        'status_code',
        'duration_ms',
        'request_fingerprint',
        'idempotency_key',
        'error_code',
        'error_message',
        'meta',
        'happened_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_retry' => 'boolean',
            'meta' => 'array',
            'happened_at' => 'datetime:Y-m-d H:i:s.v',
        ];
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

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
