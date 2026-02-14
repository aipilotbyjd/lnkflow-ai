<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Credential\StoreCredentialRequest;
use App\Http\Requests\Api\V1\Credential\UpdateCredentialRequest;
use App\Http\Resources\Api\V1\CredentialResource;
use App\Models\Credential;
use App\Models\Workspace;
use App\Services\CredentialTestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CredentialController extends Controller
{
    public function __construct(
        private CredentialTestService $credentialTestService
    ) {}

    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('credential.view', $workspace);

        $query = $workspace->credentials()
            ->with(['credentialType', 'creator']);

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $credentials = $query->latest()->get();

        return CredentialResource::collection($credentials);
    }

    public function store(StoreCredentialRequest $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('credential.create', $workspace);

        $credential = $workspace->credentials()->create([
            'name' => $request->validated('name'),
            'type' => $request->validated('type'),
            'data' => $request->validated('data'),
            'expires_at' => $request->validated('expires_at'),
            'created_by' => $request->user()->id,
        ]);

        $credential->load(['credentialType', 'creator']);

        return response()->json([
            'message' => 'Credential created successfully.',
            'credential' => new CredentialResource($credential),
        ], 201);
    }

    public function show(Request $request, Workspace $workspace, Credential $credential): JsonResponse
    {
        $this->authorize('credential.view', $workspace);
        $this->ensureCredentialBelongsToWorkspace($credential, $workspace);

        $credential->load(['credentialType', 'creator']);

        return response()->json([
            'credential' => new CredentialResource($credential),
        ]);
    }

    public function update(UpdateCredentialRequest $request, Workspace $workspace, Credential $credential): JsonResponse
    {
        $this->authorize('credential.update', $workspace);
        $this->ensureCredentialBelongsToWorkspace($credential, $workspace);

        $updateData = [];

        if ($request->has('name')) {
            $updateData['name'] = $request->validated('name');
        }

        if ($request->has('data')) {
            $updateData['data'] = $request->validated('data');
        }

        if ($request->has('expires_at')) {
            $updateData['expires_at'] = $request->validated('expires_at');
        }

        $credential->update($updateData);
        $credential->load(['credentialType', 'creator']);

        return response()->json([
            'message' => 'Credential updated successfully.',
            'credential' => new CredentialResource($credential),
        ]);
    }

    public function destroy(Request $request, Workspace $workspace, Credential $credential): JsonResponse
    {
        $this->authorize('credential.delete', $workspace);
        $this->ensureCredentialBelongsToWorkspace($credential, $workspace);

        $credential->delete();

        return response()->json([
            'message' => 'Credential deleted successfully.',
        ]);
    }

    public function test(Request $request, Workspace $workspace, Credential $credential): JsonResponse
    {
        $this->authorize('credential.update', $workspace);
        $this->ensureCredentialBelongsToWorkspace($credential, $workspace);

        $result = $this->credentialTestService->test($credential);

        $credential->markAsUsed();

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    private function ensureCredentialBelongsToWorkspace(Credential $credential, Workspace $workspace): void
    {
        if ($credential->workspace_id !== $workspace->id) {
            abort(404, 'Credential not found.');
        }
    }
}
