<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageDailySnapshot extends Model
{
    protected $fillable = [
        'workspace_id',
        'day',
        'credits_used',
        'executions_total',
        'executions_succeeded',
        'executions_failed',
        'nodes_executed',
        'data_transfer_bytes',
        'active_workflows',
        'peak_concurrent_executions',
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
