<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\StripeService;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(
        private StripeService $stripeService
    ) {}

    /**
     * Get billing information for a workspace.
     */
    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('workspace.manage-billing');

        $subscription = $workspace->subscription()->with('plan')->first();

        return response()->json([
            'subscription' => $subscription,
            'customer_id' => $subscription?->stripe_customer_id,
            'has_active_subscription' => $subscription?->isActive() ?? false,
        ]);
    }

    /**
     * Create a checkout session for a new subscription.
     */
    public function createCheckoutSession(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('workspace.manage-billing');

        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'billing_interval' => 'in:monthly,yearly',
            'success_url' => 'nullable|url',
            'cancel_url' => 'nullable|url',
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);

        try {
            $session = $this->stripeService->createCheckoutSession(
                $workspace,
                $plan,
                $validated['billing_interval'] ?? 'monthly',
                $validated['success_url'] ?? null,
                $validated['cancel_url'] ?? null
            );

            return response()->json([
                'checkout_url' => $session->url,
                'session_id' => $session->id,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create checkout session',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a billing portal session.
     */
    public function createPortalSession(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('workspace.manage-billing');

        $validated = $request->validate([
            'return_url' => 'nullable|url',
        ]);

        try {
            $session = $this->stripeService->createBillingPortalSession(
                $workspace,
                $validated['return_url'] ?? null
            );

            return response()->json([
                'portal_url' => $session->url,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create portal session',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel the subscription.
     */
    public function cancel(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('workspace.manage-billing');

        $validated = $request->validate([
            'immediately' => 'boolean',
        ]);

        $subscription = $workspace->subscription;

        if (! $subscription) {
            return response()->json([
                'message' => 'No active subscription found',
            ], 404);
        }

        try {
            $this->stripeService->cancelSubscription(
                $subscription,
                $validated['immediately'] ?? false
            );

            return response()->json([
                'message' => 'Subscription cancelled successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to cancel subscription',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resume a cancelled subscription.
     */
    public function resume(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('workspace.manage-billing');

        $subscription = $workspace->subscription;

        if (! $subscription) {
            return response()->json([
                'message' => 'No subscription found',
            ], 404);
        }

        try {
            $this->stripeService->resumeSubscription($subscription);

            return response()->json([
                'message' => 'Subscription resumed successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to resume subscription',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Change subscription plan.
     */
    public function changePlan(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('workspace.manage-billing');

        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'billing_interval' => 'in:monthly,yearly',
        ]);

        $subscription = $workspace->subscription;

        if (! $subscription) {
            return response()->json([
                'message' => 'No active subscription found',
            ], 404);
        }

        $newPlan = Plan::findOrFail($validated['plan_id']);

        try {
            $this->stripeService->changePlan(
                $subscription,
                $newPlan,
                $validated['billing_interval'] ?? 'monthly'
            );

            return response()->json([
                'message' => 'Plan changed successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to change plan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
