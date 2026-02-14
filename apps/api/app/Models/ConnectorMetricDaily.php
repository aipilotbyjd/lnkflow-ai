<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorMetricDaily extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'connector_key',
        'connector_operation',
        'day',
        'total_calls',
        'success_calls',
        'failure_calls',
        'retry_calls',
        'timeout_calls',
        'p50_latency_ms',
        'p95_latency_ms',
        'p99_latency_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
