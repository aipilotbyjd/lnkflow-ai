<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\ConnectorReliabilityService;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectorReliabilityController extends Controller
{
    public function __construct(
        private ConnectorReliabilityService $connectorReliabilityService
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('execution.view');

        $metrics = $this->connectorReliabilityService->metrics($workspace, $request->all());

        return response()->json([
            'metrics' => $metrics,
        ]);
    }

    public function attempts(Request $request, Workspace $workspace, string $connectorKey): JsonResponse
    {
        $this->authorize('execution.view');

        $attempts = $this->connectorReliabilityService->attempts($workspace, $connectorKey, $request->all());

        return response()->json([
            'data' => $attempts->items(),
            'meta' => [
                'current_page' => $attempts->currentPage(),
                'last_page' => $attempts->lastPage(),
                'per_page' => $attempts->perPage(),
                'total' => $attempts->total(),
            ],
        ]);
    }
}
