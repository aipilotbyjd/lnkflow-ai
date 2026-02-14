<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Workflow\ValidateWorkflowContractsRequest;
use App\Http\Resources\Api\V1\WorkflowContractSnapshotResource;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\ContractCompilerService;
use App\Services\WorkflowContractTestService;
use App\Services\WorkspacePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowContractController extends Controller
{
    public function __construct(
        private WorkspacePermissionService $permissionService,
        private ContractCompilerService $contractCompilerService,
        private WorkflowContractTestService $workflowContractTestService
    ) {}

    public function validate(ValidateWorkflowContractsRequest $request, Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'workflow.update');
        $this->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $result = $this->contractCompilerService->validateAndSnapshot(
            workflow: $workflow,
            nodes: $request->input('nodes'),
            edges: $request->input('edges'),
            strict: $request->boolean('strict')
        );

        $settings = $workflow->settings ?? [];
        $settings['contract_snapshot_id'] = $result['snapshot']->id;
        $workflow->update(['settings' => $settings]);

        return response()->json([
            'status' => $result['status'],
            'snapshot' => new WorkflowContractSnapshotResource($result['snapshot']),
            'issues' => $result['issues'],
            'edge_contracts' => $result['edge_contracts'],
        ]);
    }

    public function latest(Request $request, Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'workflow.view');
        $this->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $snapshot = $workflow->contractSnapshots()->first();
        if (! $snapshot) {
            return response()->json([
                'snapshot' => null,
                'issues' => [],
            ]);
        }

        return response()->json([
            'snapshot' => new WorkflowContractSnapshotResource($snapshot),
            'issues' => $snapshot->issues ?? [],
        ]);
    }

    public function runTests(Request $request, Workspace $workspace): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'workflow.update');

        $summary = $this->workflowContractTestService->runForWorkspace($workspace, true);

        return response()->json([
            'summary' => $summary,
        ]);
    }

    private function ensureWorkflowBelongsToWorkspace(Workflow $workflow, Workspace $workspace): void
    {
        if ($workflow->workspace_id !== $workspace->id) {
            abort(404, 'Workflow not found.');
        }
    }
}
