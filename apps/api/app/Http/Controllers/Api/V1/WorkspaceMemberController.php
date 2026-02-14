<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Workspace\UpdateMemberRequest;
use App\Http\Resources\Api\V1\WorkspaceMemberResource;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspacePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkspaceMemberController extends Controller
{
    public function __construct(
        private WorkspacePermissionService $permissionService
    ) {}

    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->permissionService->authorize($request->user(), $workspace, 'member.view');

        $members = $workspace->members()->get();

        return WorkspaceMemberResource::collection($members);
    }

    public function update(UpdateMemberRequest $request, Workspace $workspace, User $user): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'member.update');

        if ($workspace->owner_id === $user->id) {
            abort(403, 'Cannot change the role of the workspace owner.');
        }

        $newRole = $request->validated('role');
        if ($newRole === 'admin' && $workspace->owner_id !== $request->user()->id) {
            abort(403, 'Only the workspace owner can assign the admin role.');
        }

        $workspace->members()->updateExistingPivot($user->id, [
            'role' => $newRole,
        ]);

        return response()->json([
            'message' => 'Member role updated successfully.',
        ]);
    }

    public function destroy(Request $request, Workspace $workspace, User $user): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'member.remove');

        if ($workspace->owner_id === $user->id) {
            abort(403, 'Cannot remove the workspace owner.');
        }

        if ($request->user()->id === $user->id) {
            abort(403, 'Cannot remove yourself. Use the leave endpoint instead.');
        }

        $workspace->members()->detach($user->id);

        return response()->json(['message' => 'Member removed successfully.']);
    }

    public function leave(Request $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->permissionService->authorizeMembership($user, $workspace);

        if ($workspace->owner_id === $user->id) {
            abort(403, 'Workspace owner cannot leave. Transfer ownership first or delete the workspace.');
        }

        $workspace->members()->detach($user->id);

        return response()->json(['message' => 'You have left the workspace.']);
    }
}
