<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowEnvironmentRelease extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'workflow_id',
        'from_environment_id',
        'to_environment_id',
        'workflow_version_id',
        'triggered_by',
        'action',
        'commit_sha',
        'diff_summary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'diff_summary' => 'array',
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
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<WorkspaceEnvironment, $this>
     */
    public function fromEnvironment(): BelongsTo
    {
        return $this->belongsTo(WorkspaceEnvironment::class, 'from_environment_id');
    }

    /**
     * @return BelongsTo<WorkspaceEnvironment, $this>
     */
    public function toEnvironment(): BelongsTo
    {
        return $this->belongsTo(WorkspaceEnvironment::class, 'to_environment_id');
    }

    /**
     * @return BelongsTo<WorkflowVersion, $this>
     */
    public function workflowVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
