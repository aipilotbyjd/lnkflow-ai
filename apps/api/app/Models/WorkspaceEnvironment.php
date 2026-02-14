<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceEnvironment extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'name',
        'git_branch',
        'base_branch',
        'is_default',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
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
     * @return HasMany<WorkflowEnvironmentRelease, $this>
     */
    public function outboundReleases(): HasMany
    {
        return $this->hasMany(WorkflowEnvironmentRelease::class, 'from_environment_id');
    }

    /**
     * @return HasMany<WorkflowEnvironmentRelease, $this>
     */
    public function inboundReleases(): HasMany
    {
        return $this->hasMany(WorkflowEnvironmentRelease::class, 'to_environment_id');
    }
}
