<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'workflow_id',
        'version_number',
        'name',
        'description',
        'trigger_type',
        'trigger_config',
        'nodes',
        'edges',
        'viewport',
        'settings',
        'created_by',
        'change_summary',
        'is_published',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'nodes' => 'array',
            'edges' => 'array',
            'viewport' => 'array',
            'settings' => 'array',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the next version number for a workflow.
     */
    public static function getNextVersionNumber(int $workflowId): int
    {
        $max = static::where('workflow_id', $workflowId)->max('version_number');

        return ($max ?? 0) + 1;
    }

    /**
     * Publish this version.
     */
    public function publish(): void
    {
        $this->update([
            'is_published' => true,
            'published_at' => now(),
        ]);

        // Update the workflow to use this version
        $this->workflow->update([
            'current_version_id' => $this->id,
        ]);
    }

    /**
     * Create a version from a workflow.
     */
    public static function createFromWorkflow(Workflow $workflow, ?int $userId = null, ?string $changeSummary = null): static
    {
        return static::create([
            'workflow_id' => $workflow->id,
            'version_number' => static::getNextVersionNumber($workflow->id),
            'name' => $workflow->name,
            'description' => $workflow->description,
            'trigger_type' => $workflow->trigger_type?->value,
            'trigger_config' => $workflow->trigger_config,
            'nodes' => $workflow->nodes,
            'edges' => $workflow->edges,
            'viewport' => $workflow->viewport,
            'settings' => $workflow->settings,
            'created_by' => $userId,
            'change_summary' => $changeSummary,
        ]);
    }

    /**
     * Restore this version to the workflow.
     */
    public function restoreToWorkflow(): void
    {
        $this->workflow->update([
            'name' => $this->name,
            'description' => $this->description,
            'trigger_type' => $this->trigger_type,
            'trigger_config' => $this->trigger_config,
            'nodes' => $this->nodes,
            'edges' => $this->edges,
            'viewport' => $this->viewport,
            'settings' => $this->settings,
        ]);
    }
}
