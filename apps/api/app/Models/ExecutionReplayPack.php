<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionReplayPack extends Model
{
    use HasFactory;

    protected $fillable = [
        'execution_id',
        'workspace_id',
        'workflow_id',
        'source_execution_id',
        'mode',
        'deterministic_seed',
        'workflow_snapshot',
        'trigger_snapshot',
        'fixtures',
        'environment_snapshot',
        'captured_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'workflow_snapshot' => 'array',
            'trigger_snapshot' => 'array',
            'fixtures' => 'array',
            'environment_snapshot' => 'array',
            'captured_at' => 'datetime',
            'expires_at' => 'datetime',
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
     * @return BelongsTo<Execution, $this>
     */
    public function sourceExecution(): BelongsTo
    {
        return $this->belongsTo(Execution::class, 'source_execution_id');
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
