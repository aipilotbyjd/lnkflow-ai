<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Policy\UpsertWorkspacePolicyRequest;
use App\Http\Resources\Api\V1\WorkspacePolicyResource;
use App\Models\Workspace;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspacePolicyController extends Controller
{


    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('workspace.view');

        $policy = $workspace->policy;

        return response()->json([
            'policy' => $policy ? new WorkspacePolicyResource($policy) : null,
        ]);
    }

    public function upsert(UpsertWorkspacePolicyRequest $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('workspace.update');

        $policy = $workspace->policy()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            $request->validated()
        );

        return response()->json([
            'message' => 'Workspace policy saved successfully.',
            'policy' => new WorkspacePolicyResource($policy),
        ]);
    }
}
