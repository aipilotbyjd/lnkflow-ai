<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ActivityLogResource;
use App\Models\Workspace;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityLogController extends Controller
{


    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('workspace.view');

        $query = $workspace->activityLogs()
            ->with('user')
            ->orderByDesc('created_at');

        // Filter by action
        if ($request->has('action')) {
            $query->where('action', 'like', $request->input('action').'%');
        }

        // Filter by user
        if ($request->has('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        // Filter by subject type
        if ($request->has('subject_type')) {
            $query->where('subject_type', $request->input('subject_type'));
        }

        // Filter by subject id
        if ($request->has('subject_id')) {
            $query->where('subject_id', $request->input('subject_id'));
        }

        // Filter by date range
        if ($request->has('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }

        if ($request->has('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }

        $logs = $query->paginate($request->input('per_page', 50));

        return ActivityLogResource::collection($logs);
    }
}
