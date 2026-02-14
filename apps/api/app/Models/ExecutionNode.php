<?php

namespace App\Models;

use App\Enums\ExecutionNodeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExecutionNode extends Model
{
    /** @use HasFactory<\Database\Factories\ExecutionNodeFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'execution_id',
        'node_id',
        'node_type',
        'node_name',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'input_data',
        'output_data',
        'error',
        'sequence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExecutionNodeStatus::class,
            'started_at' => 'datetime:Y-m-d H:i:s.v',
            'finished_at' => 'datetime:Y-m-d H:i:s.v',
            'input_data' => 'array',
            'output_data' => 'array',
            'error' => 'array',
            'created_at' => 'datetime',
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

    public function start(): void
    {
        $this->update([
            'status' => ExecutionNodeStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function complete(?array $outputData = null): void
    {
        $this->update([
            'status' => ExecutionNodeStatus::Completed,
            'finished_at' => now(),
            'duration_ms' => (int) $this->started_at?->diffInMilliseconds(now()),
            'output_data' => $outputData,
        ]);
    }

    public function fail(array $error): void
    {
        $this->update([
            'status' => ExecutionNodeStatus::Failed,
            'finished_at' => now(),
            'duration_ms' => (int) $this->started_at?->diffInMilliseconds(now()),
            'error' => $error,
        ]);
    }

    public function skip(): void
    {
        $this->update([
            'status' => ExecutionNodeStatus::Skipped,
        ]);
    }
}
