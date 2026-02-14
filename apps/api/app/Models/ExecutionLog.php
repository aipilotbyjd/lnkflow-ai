<?php

namespace App\Models;

use App\Enums\LogLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'execution_id',
        'execution_node_id',
        'level',
        'message',
        'context',
        'logged_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => LogLevel::class,
            'context' => 'array',
            'logged_at' => 'datetime:Y-m-d H:i:s.v',
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
}
