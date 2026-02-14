<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobStatus extends Model
{
    /** @use HasFactory<\Database\Factories\JobStatusFactory> */
    use HasFactory;

    protected $fillable = [
        'job_id',
        'execution_id',
        'partition',
        'callback_token',
        'status',
        'progress',
        'result',
        'error',
        'started_at',
        'completed_at',
    ];

    protected $hidden = [
        'callback_token', // Never expose token in API responses
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => 'array',
            'error' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    public function markProcessing(): void
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    public function markCompleted(array $result = []): void
    {
        $this->update([
            'status' => 'completed',
            'progress' => 100,
            'result' => $result,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(array $error): void
    {
        $this->update([
            'status' => 'failed',
            'error' => $error,
            'completed_at' => now(),
        ]);
    }

    public function updateProgress(int $progress): void
    {
        $this->update(['progress' => min(100, max(0, $progress))]);
    }
}
