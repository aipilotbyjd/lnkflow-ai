<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Workflow;
use App\Models\Workspace;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkflowImportExportController extends Controller
{


    /**
     * Export a single workflow to JSON.
     */
    public function export(Request $request, Workspace $workspace, Workflow $workflow): StreamedResponse
    {
        $this->authorize('workflow.export');

        $exportData = $this->buildExportData($workflow);

        $filename = Str::slug($workflow->name).'-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(function () use ($exportData) {
            echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, $filename, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Export multiple workflows to JSON.
     */
    public function exportBulk(Request $request, Workspace $workspace): StreamedResponse
    {
        $this->authorize('workflow.export');

        $validated = $request->validate([
            'workflow_ids' => 'required|array',
            'workflow_ids.*' => 'exists:workflows,id',
        ]);

        $workflows = Workflow::whereIn('id', $validated['workflow_ids'])
            ->where('workspace_id', $workspace->id)
            ->get();

        $exportData = [
            'version' => '1.0',
            'exported_at' => now()->toIso8601String(),
            'workspace' => $workspace->name,
            'workflows' => $workflows->map(fn ($w) => $this->buildExportData($w))->values(),
        ];

        $filename = 'workflows-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(function () use ($exportData) {
            echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, $filename, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Import a workflow from JSON.
     */
    public function import(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('workflow.import');

        $request->validate([
            'file' => 'required|file|mimes:json|max:5120', // 5MB max
        ]);

        $content = file_get_contents($request->file('file')->getRealPath());
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'message' => 'Invalid JSON file',
                'error' => json_last_error_msg(),
            ], 422);
        }

        // Check if this is a bulk export or single workflow
        if (isset($data['workflows'])) {
            return $this->importBulk($data, $workspace, $request->user());
        }

        // Single workflow import
        $result = $this->importSingleWorkflow($data, $workspace, $request->user());

        if (isset($result['error'])) {
            return response()->json($result, 422);
        }

        return response()->json([
            'message' => 'Workflow imported successfully',
            'workflow' => $result['workflow'],
        ], 201);
    }

    /**
     * Import workflow from JSON data (API).
     */
    public function importFromJson(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('workflow.import');

        $data = $request->validate([
            'workflow' => 'required|array',
            'workflow.name' => 'required|string|max:255',
            'workflow.nodes' => 'required|array',
            'workflow.edges' => 'required|array',
        ]);

        $result = $this->importSingleWorkflow($data['workflow'], $workspace, $request->user());

        if (isset($result['error'])) {
            return response()->json($result, 422);
        }

        return response()->json([
            'message' => 'Workflow imported successfully',
            'workflow' => $result['workflow'],
        ], 201);
    }

    /**
     * Build export data for a workflow.
     */
    private function buildExportData(Workflow $workflow): array
    {
        return [
            'version' => '1.0',
            'exported_at' => now()->toIso8601String(),
            'workflow' => [
                'name' => $workflow->name,
                'description' => $workflow->description,
                'icon' => $workflow->icon,
                'color' => $workflow->color,
                'trigger_type' => $workflow->trigger_type?->value,
                'trigger_config' => $workflow->trigger_config,
                'nodes' => $this->sanitizeNodes($workflow->nodes ?? []),
                'edges' => $workflow->edges ?? [],
                'viewport' => $workflow->viewport,
                'settings' => $workflow->settings,
            ],
            'metadata' => [
                'original_id' => $workflow->id,
                'original_workspace' => $workflow->workspace->name,
                'created_at' => $workflow->created_at->toIso8601String(),
                'updated_at' => $workflow->updated_at->toIso8601String(),
                'execution_count' => $workflow->execution_count,
                'success_rate' => $workflow->success_rate,
            ],
        ];
    }

    /**
     * Sanitize nodes for export (remove sensitive data).
     */
    private function sanitizeNodes(array $nodes): array
    {
        return collect($nodes)->map(function ($node) {
            // Remove credential data but keep references
            if (isset($node['data']['credentials'])) {
                $node['data']['credentials'] = array_map(function ($cred) {
                    if (is_array($cred)) {
                        return [
                            'type' => $cred['type'] ?? 'unknown',
                            'name' => $cred['name'] ?? 'Credential',
                            '_placeholder' => true,
                        ];
                    }

                    return ['_placeholder' => true];
                }, $node['data']['credentials']);
            }

            return $node;
        })->toArray();
    }

    /**
     * Import a single workflow.
     */
    private function importSingleWorkflow(array $data, Workspace $workspace, $user): array
    {
        // Extract workflow data (handle both wrapped and unwrapped formats)
        $workflowData = $data['workflow'] ?? $data;

        // Validate required fields
        $validator = Validator::make($workflowData, [
            'name' => 'required|string|max:255',
            'nodes' => 'required|array',
            'edges' => 'required|array',
        ]);

        if ($validator->fails()) {
            return [
                'error' => 'validation_failed',
                'message' => 'Invalid workflow data',
                'errors' => $validator->errors(),
            ];
        }

        // Generate unique name if exists
        $name = $workflowData['name'];
        $counter = 1;
        while (Workflow::where('workspace_id', $workspace->id)->where('name', $name)->exists()) {
            $name = $workflowData['name'].' ('.$counter++.')';
        }

        // Create the workflow
        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'name' => $name,
            'description' => $workflowData['description'] ?? null,
            'icon' => $workflowData['icon'] ?? null,
            'color' => $workflowData['color'] ?? null,
            'trigger_type' => $workflowData['trigger_type'] ?? 'manual',
            'trigger_config' => $workflowData['trigger_config'] ?? null,
            'nodes' => $this->processImportedNodes($workflowData['nodes'] ?? []),
            'edges' => $workflowData['edges'] ?? [],
            'viewport' => $workflowData['viewport'] ?? null,
            'settings' => $workflowData['settings'] ?? null,
            'is_active' => false, // Start inactive
        ]);

        return [
            'workflow' => [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'imported_name' => $name !== $workflowData['name'] ? $name : null,
            ],
        ];
    }

    /**
     * Import multiple workflows.
     */
    private function importBulk(array $data, Workspace $workspace, $user): JsonResponse
    {
        $workflows = $data['workflows'] ?? [];
        $imported = [];
        $failed = [];

        foreach ($workflows as $index => $workflowData) {
            $result = $this->importSingleWorkflow($workflowData, $workspace, $user);

            if (isset($result['error'])) {
                $failed[] = [
                    'index' => $index,
                    'name' => $workflowData['workflow']['name'] ?? 'Unknown',
                    'error' => $result['message'],
                ];
            } else {
                $imported[] = $result['workflow'];
            }
        }

        return response()->json([
            'message' => sprintf('%d workflow(s) imported, %d failed', count($imported), count($failed)),
            'imported' => $imported,
            'failed' => $failed,
        ], count($failed) > 0 ? 207 : 201);
    }

    /**
     * Process imported nodes (regenerate IDs, handle placeholders).
     */
    private function processImportedNodes(array $nodes): array
    {
        $idMapping = [];

        // First pass: generate new IDs
        foreach ($nodes as &$node) {
            $oldId = $node['id'];
            $newId = Str::uuid()->toString();
            $idMapping[$oldId] = $newId;
            $node['id'] = $newId;
        }

        // Second pass: update references
        foreach ($nodes as &$node) {
            // Update any node references in data
            if (isset($node['data'])) {
                $node['data'] = $this->updateNodeReferences($node['data'], $idMapping);
            }
        }

        return $nodes;
    }

    /**
     * Update node references in data.
     */
    private function updateNodeReferences(array $data, array $idMapping): array
    {
        array_walk_recursive($data, function (&$value) use ($idMapping) {
            if (is_string($value) && isset($idMapping[$value])) {
                $value = $idMapping[$value];
            }
        });

        return $data;
    }
}
