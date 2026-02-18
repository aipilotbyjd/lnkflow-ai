<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AI\GenerateWorkflowRequest;
use App\Http\Requests\Api\V1\AI\RefineWorkflowRequest;
use App\Http\Resources\Api\V1\AiGenerationLogResource;
use App\Http\Resources\Api\V1\WorkflowResource;
use App\Models\AiGenerationLog;
use App\Models\Workspace;
use App\Services\AiWorkflowGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AiWorkflowGeneratorController extends Controller
{
    public function __construct(
        private AiWorkflowGeneratorService $generatorService
    ) {}

    public function generate(GenerateWorkflowRequest $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('workflow.create');

        $validated = $request->validated();
        $dryRun = $validated['options']['dry_run'] ?? false;

        $result = $this->generatorService->generate(
            workspace: $workspace,
            user: $request->user(),
            prompt: $validated['prompt'],
            credentialIds: $validated['credential_ids'] ?? [],
            dryRun: $dryRun,
        );

        if (! $dryRun && ! empty($result['workflow']['nodes'])) {
            $workflow = DB::transaction(function () use ($request, $workspace, $result) {
                $workflowData = $result['workflow'];
                $workflow = $workspace->workflows()->create([
                    'name' => $workflowData['name'] ?? 'AI Generated Workflow',
                    'description' => $workflowData['description'] ?? null,
                    'trigger_type' => $workflowData['trigger_type'] ?? 'manual',
                    'trigger_config' => $workflowData['trigger_config'] ?? [],
                    'nodes' => $workflowData['nodes'] ?? [],
                    'edges' => $workflowData['edges'] ?? [],
                    'created_by' => $request->user()->id,
                ]);

                $result['log']->update([
                    'workflow_id' => $workflow->id,
                    'status' => 'accepted',
                ]);

                return $workflow;
            });

            $workflow->load('creator');

            return response()->json([
                'message' => 'Workflow generated and created successfully.',
                'workflow' => new WorkflowResource($workflow),
                'explanation' => $result['explanation'],
                'confidence' => $result['confidence'],
                'generation_log' => new AiGenerationLogResource($result['log']),
            ], 201);
        }

        return response()->json([
            'message' => 'Workflow generated successfully.',
            'workflow' => $result['workflow'],
            'explanation' => $result['explanation'],
            'confidence' => $result['confidence'],
            'generation_log' => new AiGenerationLogResource($result['log']),
        ]);
    }

    public function refine(RefineWorkflowRequest $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('workflow.create');

        $validated = $request->validated();

        $generationLog = AiGenerationLog::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($validated['generation_log_id']);

        $result = $this->generatorService->refine(
            workspace: $workspace,
            user: $request->user(),
            generationLog: $generationLog,
            feedback: $validated['feedback'],
        );

        return response()->json([
            'message' => 'Workflow refined successfully.',
            'workflow' => $result['workflow'],
            'changes' => $result['changes'],
            'generation_log' => new AiGenerationLogResource($result['log']),
        ]);
    }

    public function history(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('workflow.view');

        $logs = $workspace->aiGenerationLogs()
            ->with('user')
            ->latest()
            ->paginate(min($request->integer('per_page', 20), 100));

        return AiGenerationLogResource::collection($logs);
    }

    public function destroyHistory(Request $request, Workspace $workspace, AiGenerationLog $aiGenerationLog): JsonResponse
    {
        $this->authorize('workflow.delete');

        if ($aiGenerationLog->workspace_id !== $workspace->id) {
            abort(404, 'Generation log not found.');
        }

        $aiGenerationLog->delete();

        return response()->json([
            'message' => 'Generation log deleted successfully.',
        ]);
    }
}
