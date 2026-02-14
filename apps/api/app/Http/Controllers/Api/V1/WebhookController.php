<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Webhook\StoreWebhookRequest;
use App\Http\Requests\Api\V1\Webhook\UpdateWebhookRequest;
use App\Http\Resources\Api\V1\WebhookResource;
use App\Models\Webhook;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WebhookController extends Controller
{
    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('webhook.view');

        $query = Webhook::query()
            ->where('workspace_id', $workspace->id)
            ->with('workflow');

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $webhooks = $query->latest()->get();

        return WebhookResource::collection($webhooks);
    }

    public function store(StoreWebhookRequest $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('webhook.create');

        $webhook = Webhook::create([
            'workflow_id' => $request->validated('workflow_id'),
            'workspace_id' => $workspace->id,
            'path' => $request->validated('path'),
            'methods' => $request->validated('methods', ['POST']),
            'is_active' => $request->validated('is_active', true),
            'auth_type' => $request->validated('auth_type', 'none'),
            'auth_config' => $request->validated('auth_config'),
            'rate_limit' => $request->validated('rate_limit'),
            'response_mode' => $request->validated('response_mode', 'immediate'),
            'response_status' => $request->validated('response_status', 200),
            'response_body' => $request->validated('response_body'),
        ]);

        return response()->json([
            'message' => 'Webhook created successfully.',
            'webhook' => new WebhookResource($webhook),
        ], 201);
    }

    public function show(Request $request, Workspace $workspace, Webhook $webhook): JsonResponse
    {
        $this->authorize('webhook.view');
        $this->ensureWebhookBelongsToWorkspace($webhook, $workspace);

        return response()->json([
            'webhook' => new WebhookResource($webhook),
        ]);
    }

    public function update(UpdateWebhookRequest $request, Workspace $workspace, Webhook $webhook): JsonResponse
    {
        $this->authorize('webhook.update');
        $this->ensureWebhookBelongsToWorkspace($webhook, $workspace);

        $updateData = array_filter($request->validated(), fn ($value) => $value !== null);

        $webhook->update($updateData);

        return response()->json([
            'message' => 'Webhook updated successfully.',
            'webhook' => new WebhookResource($webhook),
        ]);
    }

    public function destroy(Request $request, Workspace $workspace, Webhook $webhook): JsonResponse
    {
        $this->authorize('webhook.delete');
        $this->ensureWebhookBelongsToWorkspace($webhook, $workspace);

        $webhook->delete();

        return response()->json([
            'message' => 'Webhook deleted successfully.',
        ]);
    }

    public function regenerateUuid(Request $request, Workspace $workspace, Webhook $webhook): JsonResponse
    {
        $this->authorize('webhook.update');
        $this->ensureWebhookBelongsToWorkspace($webhook, $workspace);

        $webhook->update([
            'uuid' => \Illuminate\Support\Str::uuid()->toString(),
        ]);

        return response()->json([
            'message' => 'Webhook URL regenerated successfully.',
            'webhook' => new WebhookResource($webhook),
        ]);
    }

    public function activate(Request $request, Workspace $workspace, Webhook $webhook): JsonResponse
    {
        $this->authorize('webhook.update');
        $this->ensureWebhookBelongsToWorkspace($webhook, $workspace);

        $webhook->activate();

        return response()->json([
            'message' => 'Webhook activated.',
            'webhook' => new WebhookResource($webhook),
        ]);
    }

    public function deactivate(Request $request, Workspace $workspace, Webhook $webhook): JsonResponse
    {
        $this->authorize('webhook.update');
        $this->ensureWebhookBelongsToWorkspace($webhook, $workspace);

        $webhook->deactivate();

        return response()->json([
            'message' => 'Webhook deactivated.',
            'webhook' => new WebhookResource($webhook),
        ]);
    }

    public function forWorkflow(Request $request, Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $this->authorize('webhook.view');

        if ($workflow->workspace_id !== $workspace->id) {
            abort(404, 'Workflow not found.');
        }

        $webhook = $workflow->webhook;

        if (! $webhook) {
            return response()->json([
                'webhook' => null,
            ]);
        }

        return response()->json([
            'webhook' => new WebhookResource($webhook),
        ]);
    }

    private function ensureWebhookBelongsToWorkspace(Webhook $webhook, Workspace $workspace): void
    {
        if ($webhook->workspace_id !== $workspace->id) {
            abort(404, 'Webhook not found.');
        }
    }
}
