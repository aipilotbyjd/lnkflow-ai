<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WorkflowVersionResource;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use App\Services\ActivityLogService;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkflowVersionController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLogService
    ) {}

    /**
     * List all versions for a workflow.
     */
    public function index(Request $request, Workspace $workspace, Workflow $workflow): AnonymousResourceCollection
    {
        $this->authorize('workflow.view');

        $versions = $workflow->versions()
            ->with('creator')
            ->orderByDesc('version_number')
            ->paginate($request->integer('per_page', 20));

        return WorkflowVersionResource::collection($versions);
    }

    /**
     * Show a specific version.
     */
    public function show(Request $request, Workspace $workspace, Workflow $workflow, WorkflowVersion $version): JsonResponse
    {
        $this->authorize('workflow.view');

        return response()->json([
            'data' => new WorkflowVersionResource($version->load('creator')),
        ]);
    }

    /**
     * Create a new version (snapshot current workflow state).
     */
    public function store(Request $request, Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $this->authorize('workflow.update');

        $validated = $request->validate([
            'change_summary' => 'nullable|string|max:500',
        ]);

        $version = WorkflowVersion::createFromWorkflow(
            $workflow,
            $request->user()->id,
            $validated['change_summary'] ?? null
        );

        $this->activityLogService->log(
            $workspace,
            $request->user(),
            'workflow_version.created',
            "Created version {$version->version_number} of workflow '{$workflow->name}'",
            $version
        );

        return response()->json([
            'message' => 'Version created successfully.',
            'data' => new WorkflowVersionResource($version),
        ], 201);
    }

    /**
     * Publish a version (make it the active version).
     */
    public function publish(Request $request, Workspace $workspace, Workflow $workflow, WorkflowVersion $version): JsonResponse
    {
        $this->authorize('workflow.update');

        $version->publish();

        $this->activityLogService->log(
            $workspace,
            $request->user(),
            'workflow_version.published',
            "Published version {$version->version_number} of workflow '{$workflow->name}'",
            $version
        );

        return response()->json([
            'message' => 'Version published successfully.',
            'data' => new WorkflowVersionResource($version),
        ]);
    }

    /**
     * Restore a version to the workflow.
     */
    public function restore(Request $request, Workspace $workspace, Workflow $workflow, WorkflowVersion $version): JsonResponse
    {
        $this->authorize('workflow.update');

        // Create a new version to preserve current state before restoring
        $backupVersion = WorkflowVersion::createFromWorkflow(
            $workflow,
            $request->user()->id,
            "Backup before restoring to version {$version->version_number}"
        );

        $version->restoreToWorkflow();

        $this->activityLogService->log(
            $workspace,
            $request->user(),
            'workflow_version.restored',
            "Restored workflow '{$workflow->name}' to version {$version->version_number}",
            $version
        );

        return response()->json([
            'message' => "Workflow restored to version {$version->version_number}. A backup was created as version {$backupVersion->version_number}.",
            'data' => new WorkflowVersionResource($version),
            'backup_version' => $backupVersion->version_number,
        ]);
    }

    /**
     * Compare two versions.
     */
    public function compare(Request $request, Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $this->authorize('workflow.view');

        $validated = $request->validate([
            'from_version' => 'required|integer',
            'to_version' => 'required|integer',
        ]);

        $fromVersion = $workflow->versions()->where('version_number', $validated['from_version'])->firstOrFail();
        $toVersion = $workflow->versions()->where('version_number', $validated['to_version'])->firstOrFail();

        $diff = $this->computeDiff($fromVersion, $toVersion);

        return response()->json([
            'from_version' => new WorkflowVersionResource($fromVersion),
            'to_version' => new WorkflowVersionResource($toVersion),
            'diff' => $diff,
        ]);
    }

    /**
     * Compute differences between two versions.
     */
    private function computeDiff(WorkflowVersion $from, WorkflowVersion $to): array
    {
        $diff = [];

        // Compare basic fields
        $fields = ['name', 'description', 'trigger_type', 'trigger_config', 'settings'];
        foreach ($fields as $field) {
            if ($from->$field !== $to->$field) {
                $diff[$field] = [
                    'from' => $from->$field,
                    'to' => $to->$field,
                ];
            }
        }

        // Compare nodes
        $fromNodes = collect($from->nodes)->keyBy('id');
        $toNodes = collect($to->nodes)->keyBy('id');

        $addedNodes = $toNodes->diffKeys($fromNodes)->values();
        $removedNodes = $fromNodes->diffKeys($toNodes)->values();
        $modifiedNodes = [];

        foreach ($toNodes as $id => $toNode) {
            if (isset($fromNodes[$id])) {
                $fromNode = $fromNodes[$id];
                if (json_encode($fromNode) !== json_encode($toNode)) {
                    $modifiedNodes[] = [
                        'id' => $id,
                        'from' => $fromNode,
                        'to' => $toNode,
                    ];
                }
            }
        }

        $diff['nodes'] = [
            'added' => $addedNodes,
            'removed' => $removedNodes,
            'modified' => $modifiedNodes,
        ];

        // Compare edges
        $fromEdges = collect($from->edges)->keyBy('id');
        $toEdges = collect($to->edges)->keyBy('id');

        $diff['edges'] = [
            'added' => $toEdges->diffKeys($fromEdges)->values(),
            'removed' => $fromEdges->diffKeys($toEdges)->values(),
        ];

        return $diff;
    }
}
