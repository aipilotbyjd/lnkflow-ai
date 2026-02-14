<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Workspace\StoreInvitationRequest;
use App\Http\Resources\Api\V1\InvitationResource;
use App\Http\Resources\Api\V1\WorkspaceResource;
use App\Models\Invitation;
use App\Models\Workspace;
use App\Notifications\WorkspaceInvitationNotification;
use App\Services\WorkspacePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Notification;

class InvitationController extends Controller
{
    public function __construct(
        private WorkspacePermissionService $permissionService
    ) {}

    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->permissionService->authorize($request->user(), $workspace, 'member.view');

        $invitations = $workspace->invitations()
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->with('inviter')
            ->get();

        return InvitationResource::collection($invitations);
    }

    public function store(StoreInvitationRequest $request, Workspace $workspace): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'member.invite');

        if ($request->validated('role') === 'admin' && $workspace->owner_id !== $request->user()->id) {
            abort(403, 'Only the workspace owner can invite users with the admin role.');
        }

        $email = $request->validated('email');

        $existingMember = $workspace->members()->where('email', $email)->exists();
        if ($existingMember) {
            abort(422, 'This user is already a member of this workspace.');
        }

        $existingInvitation = $workspace->invitations()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists();

        if ($existingInvitation) {
            abort(422, 'An invitation has already been sent to this email address.');
        }

        $invitation = $workspace->invitations()->create([
            'email' => $email,
            'role' => $request->validated('role'),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $email)
            ->notify(new WorkspaceInvitationNotification($invitation));

        return response()->json([
            'message' => 'Invitation sent successfully.',
            'invitation' => new InvitationResource($invitation->load('inviter')),
        ], 201);
    }

    public function destroy(Request $request, Workspace $workspace, Invitation $invitation): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'member.invite');

        if ($invitation->workspace_id !== $workspace->id) {
            abort(404);
        }

        $invitation->delete();

        return response()->json(['message' => 'Invitation cancelled successfully.']);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = Invitation::query()->where('token', $token)->firstOrFail();

        if ($invitation->isAccepted()) {
            abort(422, 'This invitation has already been accepted.');
        }

        if ($invitation->isExpired()) {
            abort(422, 'This invitation has expired.');
        }

        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Please sign in to accept this invitation.',
                'requires_authentication' => true,
            ], 401);
        }

        if ($user->email !== $invitation->email) {
            abort(403, 'This invitation was sent to a different email address.');
        }

        if ($invitation->workspace->members()->where('user_id', $user->id)->exists()) {
            $invitation->update(['accepted_at' => now()]);

            return response()->json([
                'message' => 'You are already a member of this workspace.',
                'workspace' => new WorkspaceResource($invitation->workspace),
            ]);
        }

        $invitation->workspace->members()->attach($user->id, [
            'role' => $invitation->role,
            'joined_at' => now(),
        ]);

        $invitation->update(['accepted_at' => now()]);

        return response()->json([
            'message' => 'Invitation accepted successfully.',
            'workspace' => new WorkspaceResource($invitation->workspace),
        ]);
    }

    public function decline(Request $request, string $token): JsonResponse
    {
        $invitation = Invitation::query()->where('token', $token)->firstOrFail();

        if ($invitation->isAccepted()) {
            abort(422, 'This invitation has already been accepted.');
        }

        $user = $request->user();

        if (! $user || $user->email !== $invitation->email) {
            abort(403, 'You do not have permission to decline this invitation.');
        }

        $invitation->delete();

        return response()->json(['message' => 'Invitation declined successfully.']);
    }
}
