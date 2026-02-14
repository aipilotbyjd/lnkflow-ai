<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Models\Workspace;
use App\Services\CostOptimizerService;
use App\Services\WorkspacePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OptimizationController extends Controller
{
    public function __construct(
        private WorkspacePermissionService $permissionService,
        private CostOptimizerService $costOptimizerService
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'execution.view');

        return response()->json([
            'recommendations' => $this->costOptimizerService->recommendations($workspace),
        ]);
    }

    public function estimateExecution(Request $request, Workspace $workspace, Execution $execution): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'execution.view');

        if ($execution->workspace_id !== $workspace->id) {
            abort(404, 'Execution not found.');
        }

        $execution->load('connectorAttempts');

        $estimated = $this->costOptimizerService->calculateExecutionEstimatedCost($execution);

        return response()->json([
            'execution_id' => $execution->id,
            'estimated_cost_usd' => $estimated,
        ]);
    }
}
