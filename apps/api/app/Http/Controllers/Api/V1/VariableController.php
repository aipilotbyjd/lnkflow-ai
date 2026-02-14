<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Variable\StoreVariableRequest;
use App\Http\Requests\Api\V1\Variable\UpdateVariableRequest;
use App\Http\Resources\Api\V1\VariableResource;
use App\Models\Variable;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VariableController extends Controller
{
    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('variable.view', $workspace);

        $query = $workspace->variables()->with(['creator']);

        if ($request->filled('is_secret')) {
            $query->where('is_secret', filter_var($request->input('is_secret'), FILTER_VALIDATE_BOOLEAN));
        }

        $variables = $query->latest()->get();

        return VariableResource::collection($variables);
    }

    public function store(StoreVariableRequest $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('variable.create', $workspace);

        $variable = new Variable([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'key' => $request->validated('key'),
            'description' => $request->validated('description'),
            'is_secret' => $request->validated('is_secret', false),
        ]);

        // Set value after is_secret is set for proper encryption
        $variable->value = $request->validated('value');
        $variable->save();

        $variable->load(['creator']);

        return response()->json([
            'message' => 'Variable created successfully.',
            'variable' => new VariableResource($variable),
        ], 201);
    }

    public function show(Request $request, Workspace $workspace, Variable $variable): JsonResponse
    {
        $this->authorize('variable.view', $workspace);
        $this->ensureVariableBelongsToWorkspace($variable, $workspace);

        $variable->load(['creator']);

        return response()->json([
            'variable' => new VariableResource($variable),
        ]);
    }

    public function update(UpdateVariableRequest $request, Workspace $workspace, Variable $variable): JsonResponse
    {
        $this->authorize('variable.update', $workspace);
        $this->ensureVariableBelongsToWorkspace($variable, $workspace);

        if ($request->has('key')) {
            $variable->key = $request->validated('key');
        }

        if ($request->has('description')) {
            $variable->description = $request->validated('description');
        }

        if ($request->has('is_secret')) {
            $variable->is_secret = $request->validated('is_secret');
        }

        if ($request->has('value')) {
            $variable->value = $request->validated('value');
        }

        $variable->save();
        $variable->load(['creator']);

        return response()->json([
            'message' => 'Variable updated successfully.',
            'variable' => new VariableResource($variable),
        ]);
    }

    public function destroy(Request $request, Workspace $workspace, Variable $variable): JsonResponse
    {
        $this->authorize('variable.delete', $workspace);
        $this->ensureVariableBelongsToWorkspace($variable, $workspace);

        $variable->delete();

        return response()->json([
            'message' => 'Variable deleted successfully.',
        ]);
    }

    private function ensureVariableBelongsToWorkspace(Variable $variable, Workspace $workspace): void
    {
        if ($variable->workspace_id !== $workspace->id) {
            abort(404, 'Variable not found.');
        }
    }
}
