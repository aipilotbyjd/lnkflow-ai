<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Models\Workspace;
use App\Services\TimeTravelDebuggerService;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExecutionDebuggerController extends Controller
{
    public function __construct(
        private TimeTravelDebuggerService $timeTravelDebuggerService
    ) {}

    public function timeline(Request $request, Workspace $workspace, Execution $execution): JsonResponse
    {
        $this->authorize('execution.view');
        $this->ensureExecutionBelongsToWorkspace($execution, $workspace);

        $execution->load(['nodes', 'logs']);

        return response()->json([
            'timeline' => $this->timeTravelDebuggerService->timeline($execution),
        ]);
    }

    public function snapshot(Request $request, Workspace $workspace, Execution $execution): JsonResponse
    {
        $this->authorize('execution.view');
        $this->ensureExecutionBelongsToWorkspace($execution, $workspace);

        $sequence = $request->integer('sequence', 0);

        return response()->json([
            'snapshot' => $this->timeTravelDebuggerService->snapshotAt($execution, $sequence),
        ]);
    }

    public function diff(Request $request, Workspace $workspace, Execution $execution): JsonResponse
    {
        $this->authorize('execution.view');
        $this->ensureExecutionBelongsToWorkspace($execution, $workspace);

        $compareWith = Execution::query()->findOrFail($request->integer('compare_with'));
        $this->ensureExecutionBelongsToWorkspace($compareWith, $workspace);

        return response()->json([
            'diff' => $this->timeTravelDebuggerService->diff($execution, $compareWith),
        ]);
    }

    private function ensureExecutionBelongsToWorkspace(Execution $execution, Workspace $workspace): void
    {
        if ($execution->workspace_id !== $workspace->id) {
            abort(404, 'Execution not found.');
        }
    }
}
