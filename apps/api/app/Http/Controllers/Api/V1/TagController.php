<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Tag\StoreTagRequest;
use App\Http\Requests\Api\V1\Tag\UpdateTagRequest;
use App\Http\Resources\Api\V1\TagResource;
use App\Models\Tag;
use App\Models\Workspace;
use App\Services\WorkspacePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TagController extends Controller
{
    public function __construct(
        private WorkspacePermissionService $permissionService
    ) {}

    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->permissionService->authorize($request->user(), $workspace, 'tag.view');

        $tags = $workspace->tags()
            ->withCount('workflows')
            ->orderBy('name')
            ->get();

        return TagResource::collection($tags);
    }

    public function store(StoreTagRequest $request, Workspace $workspace): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'tag.create');

        $tag = $workspace->tags()->create([
            'name' => $request->validated('name'),
            'color' => $request->validated('color', '#6366f1'),
        ]);

        return response()->json([
            'message' => 'Tag created successfully.',
            'tag' => new TagResource($tag),
        ], 201);
    }

    public function update(UpdateTagRequest $request, Workspace $workspace, Tag $tag): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'tag.update');
        $this->ensureTagBelongsToWorkspace($tag, $workspace);

        $updateData = [];

        if ($request->has('name')) {
            $updateData['name'] = $request->validated('name');
        }

        if ($request->has('color')) {
            $updateData['color'] = $request->validated('color');
        }

        $tag->update($updateData);

        return response()->json([
            'message' => 'Tag updated successfully.',
            'tag' => new TagResource($tag),
        ]);
    }

    public function destroy(Request $request, Workspace $workspace, Tag $tag): JsonResponse
    {
        $this->permissionService->authorize($request->user(), $workspace, 'tag.delete');
        $this->ensureTagBelongsToWorkspace($tag, $workspace);

        $tag->delete();

        return response()->json([
            'message' => 'Tag deleted successfully.',
        ]);
    }

    private function ensureTagBelongsToWorkspace(Tag $tag, Workspace $workspace): void
    {
        if ($tag->workspace_id !== $workspace->id) {
            abort(404, 'Tag not found.');
        }
    }
}
