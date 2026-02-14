<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WorkflowTemplate extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'category',
        'icon',
        'color',
        'tags',
        'trigger_type',
        'trigger_config',
        'nodes',
        'edges',
        'viewport',
        'settings',
        'thumbnail_url',
        'instructions',
        'required_credentials',
        'is_featured',
        'is_active',
        'usage_count',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'trigger_config' => 'array',
            'nodes' => 'array',
            'edges' => 'array',
            'viewport' => 'array',
            'settings' => 'array',
            'required_credentials' => 'array',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($template) {
            if (empty($template->slug)) {
                $template->slug = Str::slug($template->name);
            }
        });
    }

    /**
     * Scope to active templates.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to featured templates.
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Scope by category.
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Get unique categories.
     */
    public static function getCategories(): array
    {
        return static::active()
            ->distinct()
            ->pluck('category')
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Increment usage count.
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    /**
     * Create a workflow from this template.
     */
    public function createWorkflow(Workspace $workspace, int $userId, ?string $name = null): Workflow
    {
        $this->incrementUsage();

        return Workflow::create([
            'workspace_id' => $workspace->id,
            'created_by' => $userId,
            'name' => $name ?? $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'color' => $this->color,
            'trigger_type' => $this->trigger_type,
            'trigger_config' => $this->trigger_config,
            'nodes' => $this->generateNewNodeIds($this->nodes ?? []),
            'edges' => $this->edges ?? [],
            'viewport' => $this->viewport,
            'settings' => $this->settings,
            'is_active' => false,
        ]);
    }

    /**
     * Generate new node IDs for workflow creation.
     */
    private function generateNewNodeIds(array $nodes): array
    {
        $idMapping = [];

        foreach ($nodes as &$node) {
            $oldId = $node['id'];
            $newId = Str::uuid()->toString();
            $idMapping[$oldId] = $newId;
            $node['id'] = $newId;
        }

        return $nodes;
    }
}
