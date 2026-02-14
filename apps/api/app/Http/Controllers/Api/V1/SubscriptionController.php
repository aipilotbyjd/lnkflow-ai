<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Subscription\StoreSubscriptionRequest;
use App\Http\Resources\Api\V1\SubscriptionResource;
use App\Models\Plan;
use App\Models\Workspace;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{


    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('workspace.view');

        $subscription = $workspace->subscription()->with('plan')->first();

        if (! $subscription) {
            return response()->json([
                'message' => 'No active subscription found.',
                'subscription' => null,
            ]);
        }

        return response()->json([
            'subscription' => new SubscriptionResource($subscription),
        ]);
    }

    public function store(StoreSubscriptionRequest $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('workspace.manage-billing');

        $plan = Plan::findOrFail($request->validated('plan_id'));

        $subscription = $workspace->subscription()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'plan_id' => $plan->id,
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]
        );

        return response()->json([
            'message' => 'Subscription updated successfully.',
            'subscription' => new SubscriptionResource($subscription->load('plan')),
        ]);
    }

    public function destroy(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('workspace.manage-billing');

        $subscription = $workspace->subscription;

        if (! $subscription) {
            abort(404, 'No active subscription found.');
        }

        $subscription->update([
            'status' => 'canceled',
            'canceled_at' => now(),
        ]);

        return response()->json([
            'message' => 'Subscription cancelled successfully.',
        ]);
    }
}
