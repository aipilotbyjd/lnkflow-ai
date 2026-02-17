<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Environment\PromoteWorkflowEnvironmentRequest;
use App\Http\Requests\Api\V1\Environment\RollbackWorkflowEnvironmentRequest;
use App\Http\Requests\Api\V1\Environment\StoreWorkspaceEnvironmentRequest;
use App\Http\Resources\Api\V1\WorkflowEnvironmentReleaseResource;
use App\Http\Resources\Api\V1\WorkspaceEnvironmentResource;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use App\Models\WorkspaceEnvironment;
use App\Services\GitEnvironmentService;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkspaceEnvironmentController extends Controller
{
    public function __construct(
        private GitEnvironmentService $gitEnvironmentService
    ) {}

    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('environment.view');

        return WorkspaceEnvironmentResource::collection($workspace->environments()->latest()->get());
    }

    public function store(StoreWorkspaceEnvironmentRequest $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('environment.create');

        $environment = $this->gitEnvironmentService->createEnvironment($workspace, $request->validated());

        return response()->json([
            'message' => 'Environment created successfully.',
            'environment' => new WorkspaceEnvironmentResource($environment),
        ], 201);
    }

    public function promote(
        PromoteWorkflowEnvironmentRequest $request,
        Workspace $workspace,
        Workflow $workflow
    ): JsonResponse {
        $this->authorize('environment.deploy');
        $this->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $from = $workspace->environments()->findOrFail($request->integer('from_environment_id'));
        $to = $workspace->environments()->findOrFail($request->integer('to_environment_id'));
        $version = $request->filled('workflow_version_id')
            ? $workflow->versions()->findOrFail($request->integer('workflow_version_id'))
            : null;

        $release = $this->gitEnvironmentService->promote(
            workflow: $workflow,
            from: $from,
            to: $to,
            userId: $request->user()->id,
            version: $version
        );

        return response()->json([
            'message' => 'Workflow promoted successfully.',
            'release' => new WorkflowEnvironmentReleaseResource($release->load(['fromEnvironment', 'toEnvironment', 'triggeredBy'])),
        ], 201);
    }

    public function rollback(
        RollbackWorkflowEnvironmentRequest $request,
        Workspace $workspace,
        Workflow $workflow
    ): JsonResponse {
        $this->authorize('environment.deploy');
        $this->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $to = $workspace->environments()->findOrFail($request->integer('to_environment_id'));
        $version = $workflow->versions()->findOrFail($request->integer('workflow_version_id'));

        $release = $this->gitEnvironmentService->rollback(
            workflow: $workflow,
            to: $to,
            userId: $request->user()->id,
            targetVersion: $version
        );

        return response()->json([
            'message' => 'Rollback recorded successfully.',
            'release' => new WorkflowEnvironmentReleaseResource($release->load(['fromEnvironment', 'toEnvironment', 'triggeredBy'])),
        ]);
    }

    public function releases(Request $request, Workspace $workspace, Workflow $workflow): AnonymousResourceCollection
    {
        $this->authorize('environment.view');
        $this->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $releases = $workflow->environmentReleases()->with(['fromEnvironment', 'toEnvironment', 'triggeredBy'])->latest()->get();

        return WorkflowEnvironmentReleaseResource::collection($releases);
    }

    private function ensureWorkflowBelongsToWorkspace(Workflow $workflow, Workspace $workspace): void
    {
        if ($workflow->workspace_id !== $workspace->id) {
            abort(404, 'Workflow not found.');
        }
    }

    private function ensureEnvironmentBelongsToWorkspace(Workspace $workspace, WorkspaceEnvironment $environment): void
    {
        if ($environment->workspace_id !== $workspace->id) {
            abort(404, 'Environment not found.');
        }
    }
}
