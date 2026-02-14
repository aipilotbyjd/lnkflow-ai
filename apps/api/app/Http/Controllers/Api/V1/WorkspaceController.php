<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Workspace\StoreWorkspaceRequest;
use App\Http\Requests\Api\V1\Workspace\UpdateWorkspaceRequest;
use App\Http\Resources\Api\V1\WorkspaceResource;
use App\Models\Workspace;
use App\Services\WorkspacePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkspaceController extends Controller
{
    public function __construct(
        private WorkspacePermissionService $permissionService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $workspaces = $request->user()->workspaces()->with('owner')->get();

        return WorkspaceResource::collection($workspaces);
    }

    public function store(StoreWorkspaceRequest $request): JsonResponse
    {
        $workspace = Workspace::query()->create([
            'name' => $request->validated('name'),
            'owner_id' => $request->user()->id,
        ]);

        $workspace->members()->attach($request->user()->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        $workspace->load('owner');

        return response()->json([
            'message' => 'Workspace created successfully.',
            'workspace' => new WorkspaceResource($workspace),
        ], 201);
    }

    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'workspace.view');

        $workspace->load('owner');

        return response()->json([
            'workspace' => new WorkspaceResource($workspace),
        ]);
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'workspace.update');

        $workspace->update($request->validated());
        $workspace->load('owner');

        return response()->json([
            'message' => 'Workspace updated successfully.',
            'workspace' => new WorkspaceResource($workspace),
        ]);
    }

    public function destroy(Request $request, Workspace $workspace): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'workspace.delete');

        if ($workspace->owner_id !== $request->user()->id) {
            abort(403, 'Only the workspace owner can delete the workspace.');
        }

        $workspace->delete();

        return response()->json([
            'message' => 'Workspace deleted successfully.',
        ]);
    }
}
