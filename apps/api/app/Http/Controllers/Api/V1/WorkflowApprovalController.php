<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Approval\WorkflowApprovalDecisionRequest;
use App\Http\Resources\Api\V1\WorkflowApprovalResource;
use App\Models\WorkflowApproval;
use App\Models\Workspace;
use App\Services\WorkflowApprovalService;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkflowApprovalController extends Controller
{
    public function __construct(
        private WorkflowApprovalService $workflowApprovalService
    ) {}

    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('execution.view');

        $approvals = $this->workflowApprovalService->inbox($workspace, $request->all());

        return WorkflowApprovalResource::collection($approvals);
    }

    public function show(Request $request, Workspace $workspace, WorkflowApproval $approval): JsonResponse
    {
        $this->authorize('execution.view');
        $this->ensureBelongsToWorkspace($approval, $workspace);

        return response()->json([
            'approval' => new WorkflowApprovalResource($approval->load(['approvedBy', 'execution', 'workflow'])),
        ]);
    }

    public function decide(
        WorkflowApprovalDecisionRequest $request,
        Workspace $workspace,
        WorkflowApproval $approval
    ): JsonResponse {
        $this->authorize('workflow.execute');
        $this->ensureBelongsToWorkspace($approval, $workspace);

        $execution = $this->workflowApprovalService->decide(
            approval: $approval,
            userId: $request->user()->id,
            decision: $request->string('decision')->toString(),
            decisionPayload: $request->input('decision_payload', [])
        );

        return response()->json([
            'message' => 'Approval decision submitted and workflow resumed.',
            'approval' => new WorkflowApprovalResource($approval->fresh(['approvedBy'])),
            'resumed_execution_id' => $execution->id,
        ]);
    }

    private function ensureBelongsToWorkspace(WorkflowApproval $approval, Workspace $workspace): void
    {
        if ($approval->workspace_id !== $workspace->id) {
            abort(404, 'Approval not found.');
        }
    }
}
