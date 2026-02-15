<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WorkflowResource;
use App\Models\Workflow;
use App\Models\WorkflowTemplate;
use App\Models\Workspace;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowTemplateController extends Controller
{


    /**
     * List all available templates.
     */
    public function index(Request $request): JsonResponse
    {
        $query = WorkflowTemplate::active()
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('name');

        // Filter by category
        if ($category = $request->query('category')) {
            $query->byCategory($category);
        }

        // Filter by search
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereJsonContains('tags', $search);
            });
        }

        // Filter featured only
        if ($request->boolean('featured')) {
            $query->featured();
        }

        $templates = $query->paginate(min($request->integer('per_page', 20), 100));

        return response()->json([
            'data' => $templates->map(fn ($t) => $this->formatTemplate($t)),
            'meta' => [
                'current_page' => $templates->currentPage(),
                'last_page' => $templates->lastPage(),
                'per_page' => $templates->perPage(),
                'total' => $templates->total(),
            ],
        ]);
    }

    /**
     * Get template categories.
     */
    public function categories(): JsonResponse
    {
        $categories = WorkflowTemplate::getCategories();

        $categoriesWithCount = collect($categories)->map(function ($category) {
            return [
                'name' => $category,
                'slug' => \Illuminate\Support\Str::slug($category),
                'count' => WorkflowTemplate::active()->byCategory($category)->count(),
            ];
        });

        return response()->json([
            'data' => $categoriesWithCount,
        ]);
    }

    /**
     * Show a specific template.
     */
    public function show(string $slug): JsonResponse
    {
        $template = WorkflowTemplate::where('slug', $slug)->active()->firstOrFail();

        return response()->json([
            'data' => $this->formatTemplate($template, true),
        ]);
    }

    /**
     * Use a template to create a workflow.
     */
    public function use(Request $request, Workspace $workspace, string $slug): JsonResponse
    {
        $this->authorize('workflow.create');

        $template = WorkflowTemplate::where('slug', $slug)->active()->firstOrFail();

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
        ]);

        $workflow = $template->createWorkflow(
            $workspace,
            $request->user()->id,
            $validated['name'] ?? null
        );

        return response()->json([
            'message' => 'Workflow created from template successfully',
            'data' => new WorkflowResource($workflow),
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'required_credentials' => $template->required_credentials,
                'instructions' => $template->instructions,
            ],
        ], 201);
    }

    /**
     * Format template for response.
     */
    private function formatTemplate(WorkflowTemplate $template, bool $includeDetails = false): array
    {
        $data = [
            'id' => $template->id,
            'name' => $template->name,
            'slug' => $template->slug,
            'description' => $template->description,
            'category' => $template->category,
            'icon' => $template->icon,
            'color' => $template->color,
            'tags' => $template->tags,
            'thumbnail_url' => $template->thumbnail_url,
            'is_featured' => $template->is_featured,
            'usage_count' => $template->usage_count,
            'required_credentials' => $template->required_credentials,
        ];

        if ($includeDetails) {
            $data['trigger_type'] = $template->trigger_type;
            $data['trigger_config'] = $template->trigger_config;
            $data['nodes'] = $template->nodes;
            $data['edges'] = $template->edges;
            $data['viewport'] = $template->viewport;
            $data['settings'] = $template->settings;
            $data['instructions'] = $template->instructions;
        }

        return $data;
    }
}
