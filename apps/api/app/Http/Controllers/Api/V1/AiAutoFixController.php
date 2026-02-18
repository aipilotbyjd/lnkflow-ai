<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AI\AnalyzeExecutionRequest;
use App\Http\Requests\Api\V1\AI\ApplyFixRequest;
use App\Http\Resources\Api\V1\AiFixSuggestionResource;
use App\Http\Resources\Api\V1\WorkflowVersionResource;
use App\Models\AiFixSuggestion;
use App\Models\Execution;
use App\Models\Workspace;
use App\Services\AiAutoFixService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AiAutoFixController extends Controller
{
    public function __construct(
        private AiAutoFixService $autoFixService
    ) {}

    public function analyze(AnalyzeExecutionRequest $request, Workspace $workspace, Execution $execution): JsonResponse
    {
        $this->authorize('execution.view');

        if ($execution->workspace_id !== $workspace->id) {
            abort(404, 'Execution not found.');
        }

        if ($execution->status->value !== 'failed') {
            return response()->json([
                'message' => 'Only failed executions can be analyzed.',
            ], 422);
        }

        $result = $this->autoFixService->analyze($execution);

        $autoApply = $request->validated()['auto_apply'] ?? false;

        if ($autoApply && ! empty($result['suggestions']) && ($result['suggestions'][0]['confidence'] ?? 0) >= 0.95) {
            $applyResult = $this->autoFixService->applyFix($result['fix_suggestion'], 0, $request->user()->id);

            return response()->json([
                'message' => 'Execution analyzed and fix auto-applied.',
                'diagnosis' => $result['diagnosis'],
                'suggestions' => $result['suggestions'],
                'fix_suggestion' => new AiFixSuggestionResource($result['fix_suggestion']->fresh()),
                'applied_version' => new WorkflowVersionResource($applyResult['version']),
            ]);
        }

        return response()->json([
            'message' => 'Execution analyzed successfully.',
            'diagnosis' => $result['diagnosis'],
            'suggestions' => $result['suggestions'],
            'fix_suggestion' => new AiFixSuggestionResource($result['fix_suggestion']),
        ]);
    }

    public function applyFix(ApplyFixRequest $request, Workspace $workspace, Execution $execution): JsonResponse
    {
        $this->authorize('workflow.update');

        if ($execution->workspace_id !== $workspace->id) {
            abort(404, 'Execution not found.');
        }

        $fixSuggestion = AiFixSuggestion::query()
            ->where('execution_id', $execution->id)
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();

        $validated = $request->validated();

        $result = $this->autoFixService->applyFix($fixSuggestion, $validated['suggestion_index'], $request->user()->id);

        return response()->json([
            'message' => 'Fix applied successfully.',
            'workflow_version' => new WorkflowVersionResource($result['version']),
            'applied_patch' => $result['applied_patch'],
        ]);
    }

    public function fixHistory(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('execution.view');

        $query = AiFixSuggestion::query()
            ->where('workspace_id', $workspace->id)
            ->latest();

        if ($request->filled('workflow_id')) {
            $query->where('workflow_id', $request->input('workflow_id'));
        }

        return AiFixSuggestionResource::collection(
            $query->paginate(min($request->integer('per_page', 20), 100))
        );
    }
}
