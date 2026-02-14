<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ExecutionRunbookResource;
use App\Models\ExecutionRunbook;
use App\Models\Workspace;
use App\Services\WorkspacePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExecutionRunbookController extends Controller
{
    public function __construct(
        private WorkspacePermissionService $permissionService
    ) {}

    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->permissionService->authorize($request->user(), $workspace, 'execution.view');

        $query = $workspace->runbooks()->with('acknowledgedBy')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return ExecutionRunbookResource::collection($query->paginate($request->integer('per_page', 20)));
    }

    public function show(Request $request, Workspace $workspace, ExecutionRunbook $runbook): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'execution.view');
        $this->ensureRunbookBelongsToWorkspace($workspace, $runbook);

        return response()->json([
            'runbook' => new ExecutionRunbookResource($runbook->load('acknowledgedBy')),
        ]);
    }

    public function acknowledge(Request $request, Workspace $workspace, ExecutionRunbook $runbook): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'execution.delete');
        $this->ensureRunbookBelongsToWorkspace($workspace, $runbook);

        $runbook->update([
            'status' => 'acknowledged',
            'acknowledged_by' => $request->user()->id,
            'acknowledged_at' => now(),
        ]);

        return response()->json([
            'message' => 'Runbook acknowledged.',
            'runbook' => new ExecutionRunbookResource($runbook->fresh('acknowledgedBy')),
        ]);
    }

    public function resolve(Request $request, Workspace $workspace, ExecutionRunbook $runbook): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'execution.delete');
        $this->ensureRunbookBelongsToWorkspace($workspace, $runbook);

        $runbook->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        return response()->json([
            'message' => 'Runbook resolved.',
            'runbook' => new ExecutionRunbookResource($runbook->fresh('acknowledgedBy')),
        ]);
    }

    private function ensureRunbookBelongsToWorkspace(Workspace $workspace, ExecutionRunbook $runbook): void
    {
        if ($runbook->workspace_id !== $workspace->id) {
            abort(404, 'Runbook not found.');
        }
    }
}
