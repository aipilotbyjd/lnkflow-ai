<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspacePolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'enabled',
        'allowed_node_types',
        'blocked_node_types',
        'allowed_ai_models',
        'blocked_ai_models',
        'max_execution_cost_usd',
        'max_ai_tokens',
        'redaction_rules',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'allowed_node_types' => 'array',
            'blocked_node_types' => 'array',
            'allowed_ai_models' => 'array',
            'blocked_ai_models' => 'array',
            'redaction_rules' => 'array',
            'max_execution_cost_usd' => 'decimal:4',
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
