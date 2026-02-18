<?php

namespace App\Http\Controllers\Api;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\CreditMeterService;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class StripeWebhookController extends Controller
{
    public function __construct(
        private StripeService $stripeService,
        private CreditMeterService $creditMeterService
    ) {}

    /**
     * Handle Stripe webhook events.
     */
    public function handle(Request $request): Response
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');

        try {
            $event = $this->stripeService->constructWebhookEvent($payload, $signature);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Stripe webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);

            return response('Invalid signature', 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook error', [
                'error' => $e->getMessage(),
            ]);

            return response('Webhook error', 400);
        }

        Log::info('Stripe webhook received', [
            'type' => $event->type,
            'id' => $event->id,
        ]);

        return match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($event),
            'customer.subscription.created' => $this->handleSubscriptionCreated($event),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($event),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event),
            'invoice.paid' => $this->handleInvoicePaid($event),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($event),
            default => response('Event not handled', 200),
        };
    }

    /**
     * Handle checkout.session.completed event.
     */
    private function handleCheckoutCompleted(\Stripe\Event $event): Response
    {
        $session = $event->data->object;
        $workspaceId = $session->metadata->workspace_id ?? null;
        $planId = $session->metadata->plan_id ?? null;

        if (! $workspaceId) {
            Log::warning('Checkout completed without workspace_id', [
                'session_id' => $session->id,
            ]);

            return response('Missing workspace_id', 200);
        }

        $workspace = Workspace::find($workspaceId);
        if (! $workspace) {
            Log::warning('Workspace not found for checkout', [
                'workspace_id' => $workspaceId,
            ]);

            return response('Workspace not found', 200);
        }

        $plan = $planId ? Plan::find($planId) : null;

        // Retrieve the actual Stripe subscription for authoritative period bounds
        $stripeSubscription = $this->stripeService->getSubscription($session->subscription);
        $priceId = $stripeSubscription->items->data[0]->price->id ?? null;
        $interval = $stripeSubscription->items->data[0]->price->recurring->interval ?? 'month';
        $billingInterval = $interval === 'year' ? 'yearly' : 'monthly';

        $periodStart = now()->setTimestamp($stripeSubscription->current_period_start);
        $periodEnd = now()->setTimestamp($stripeSubscription->current_period_end);

        // Update or create subscription with Stripe-authoritative data
        $subscription = Subscription::updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'stripe_subscription_id' => $session->subscription,
                'stripe_customer_id' => $session->customer,
                'stripe_price_id' => $priceId,
                'plan_id' => $planId,
                'status' => $this->mapStripeStatus($stripeSubscription->status),
                'billing_interval' => $billingInterval,
                'credits_monthly' => $plan?->getLimit('credits_monthly') ?? 0,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'trial_ends_at' => $stripeSubscription->trial_end
                    ? now()->setTimestamp($stripeSubscription->trial_end)
                    : null,
            ]
        );

        // Create initial usage period
        if ($plan) {
            $this->creditMeterService->createPeriod(
                workspace: $workspace,
                start: $periodStart,
                end: $periodEnd,
                limit: $plan->getLimit('credits_monthly') ?? 0,
                subscriptionId: $subscription->id,
            );
        }

        Log::info('Subscription created from checkout', [
            'workspace_id' => $workspaceId,
            'subscription_id' => $subscription->id,
            'plan_id' => $planId,
            'billing_interval' => $billingInterval,
        ]);

        return response('OK', 200);
    }

    /**
     * Handle customer.subscription.created event.
     */
    private function handleSubscriptionCreated(\Stripe\Event $event): Response
    {
        $stripeSubscription = $event->data->object;
        $workspaceId = $stripeSubscription->metadata->workspace_id ?? null;

        if (! $workspaceId) {
            return response('OK', 200);
        }

        $planId = $stripeSubscription->metadata->plan_id ?? null;
        $plan = $planId ? Plan::find($planId) : null;
        $priceId = $stripeSubscription->items->data[0]->price->id ?? null;
        $interval = $stripeSubscription->items->data[0]->price->recurring->interval ?? 'month';
        $billingInterval = $interval === 'year' ? 'yearly' : 'monthly';

        $periodStart = now()->setTimestamp($stripeSubscription->current_period_start);
        $periodEnd = now()->setTimestamp($stripeSubscription->current_period_end);

        $subscription = Subscription::updateOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'stripe_subscription_id' => $stripeSubscription->id,
                'stripe_customer_id' => $stripeSubscription->customer,
                'stripe_price_id' => $priceId,
                'plan_id' => $planId,
                'status' => $this->mapStripeStatus($stripeSubscription->status),
                'billing_interval' => $billingInterval,
                'credits_monthly' => $plan?->getLimit('credits_monthly') ?? 0,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'trial_ends_at' => $stripeSubscription->trial_end
                    ? now()->setTimestamp($stripeSubscription->trial_end)
                    : null,
            ]
        );

        // Create usage period if plan exists and no current period yet
        $workspace = Workspace::find($workspaceId);
        if ($workspace && $plan && ! $this->creditMeterService->currentPeriod($workspace->id)) {
            $this->creditMeterService->createPeriod(
                workspace: $workspace,
                start: $periodStart,
                end: $periodEnd,
                limit: $plan->getLimit('credits_monthly') ?? 0,
                subscriptionId: $subscription->id,
            );
        }

        return response('OK', 200);
    }

    /**
     * Handle customer.subscription.updated event.
     */
    private function handleSubscriptionUpdated(\Stripe\Event $event): Response
    {
        $stripeSubscription = $event->data->object;

        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscription->id)->first();

        if (! $subscription) {
            return response('Subscription not found', 200);
        }

        // Resolve plan from Stripe price ID
        $priceId = $stripeSubscription->items->data[0]->price->id ?? null;
        $interval = $stripeSubscription->items->data[0]->price->recurring->interval ?? 'month';
        $billingInterval = $interval === 'year' ? 'yearly' : 'monthly';

        $updateData = [
            'status' => $this->mapStripeStatus($stripeSubscription->status),
            'stripe_price_id' => $priceId,
            'billing_interval' => $billingInterval,
            'current_period_start' => now()->setTimestamp($stripeSubscription->current_period_start),
            'current_period_end' => now()->setTimestamp($stripeSubscription->current_period_end),
            'canceled_at' => $stripeSubscription->canceled_at
                ? now()->setTimestamp($stripeSubscription->canceled_at)
                : null,
        ];

        if ($priceId) {
            $plan = Plan::query()
                ->where('stripe_prices->monthly', $priceId)
                ->orWhere('stripe_prices->yearly', $priceId)
                ->first();

            if ($plan) {
                $updateData['plan_id'] = $plan->id;
                $updateData['credits_monthly'] = $plan->getLimit('credits_monthly') ?? 0;
            }
        }

        $subscription->update($updateData);

        Log::info('Subscription updated', [
            'subscription_id' => $subscription->id,
            'status' => $subscription->status,
            'billing_interval' => $billingInterval,
        ]);

        return response('OK', 200);
    }

    /**
     * Handle customer.subscription.deleted event.
     */
    private function handleSubscriptionDeleted(\Stripe\Event $event): Response
    {
        $stripeSubscription = $event->data->object;

        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscription->id)->first();

        if ($subscription) {
            $subscription->update([
                'status' => SubscriptionStatus::Canceled,
                'canceled_at' => now(),
            ]);

            Log::info('Subscription deleted', [
                'subscription_id' => $subscription->id,
            ]);
        }

        return response('OK', 200);
    }

    /**
     * Handle invoice.paid event.
     */
    private function handleInvoicePaid(\Stripe\Event $event): Response
    {
        $invoice = $event->data->object;

        $subscription = Subscription::where('stripe_subscription_id', $invoice->subscription)
            ->with('plan')
            ->first();

        if (! $subscription) {
            return response('OK', 200);
        }

        $subscription->update([
            'status' => SubscriptionStatus::Active,
        ]);

        // Rotate usage period on billing renewal (not the first invoice)
        if ($invoice->billing_reason === 'subscription_cycle' && $subscription->plan) {
            $workspace = Workspace::find($subscription->workspace_id);

            if ($workspace) {
                // Retrieve Stripe subscription for authoritative period bounds
                $stripeSubscription = $this->stripeService->getSubscription($subscription->stripe_subscription_id);

                $periodStart = now()->setTimestamp($stripeSubscription->current_period_start);
                $periodEnd = now()->setTimestamp($stripeSubscription->current_period_end);

                $subscription->update([
                    'current_period_start' => $periodStart,
                    'current_period_end' => $periodEnd,
                ]);

                $this->creditMeterService->createPeriod(
                    workspace: $workspace,
                    start: $periodStart,
                    end: $periodEnd,
                    limit: $subscription->plan->getLimit('credits_monthly') ?? 0,
                    subscriptionId: $subscription->id,
                );
            }
        }

        Log::info('Invoice paid', [
            'subscription_id' => $subscription->id,
            'amount' => $invoice->amount_paid / 100,
            'billing_reason' => $invoice->billing_reason ?? 'unknown',
        ]);

        return response('OK', 200);
    }

    /**
     * Handle invoice.payment_failed event.
     */
    private function handleInvoicePaymentFailed(\Stripe\Event $event): Response
    {
        $invoice = $event->data->object;

        $subscription = Subscription::where('stripe_subscription_id', $invoice->subscription)->first();

        if ($subscription) {
            $subscription->update([
                'status' => SubscriptionStatus::PastDue,
            ]);

            Log::warning('Invoice payment failed', [
                'subscription_id' => $subscription->id,
                'invoice_id' => $invoice->id,
            ]);

            // TODO: Send email notification to workspace owner
        }

        return response('OK', 200);
    }

    /**
     * Map Stripe subscription status to our enum.
     */
    private function mapStripeStatus(string $status): SubscriptionStatus
    {
        return match ($status) {
            'active' => SubscriptionStatus::Active,
            'trialing' => SubscriptionStatus::Trialing,
            'past_due' => SubscriptionStatus::PastDue,
            'canceled', 'unpaid' => SubscriptionStatus::Canceled,
            'incomplete', 'incomplete_expired' => SubscriptionStatus::Incomplete,
            default => SubscriptionStatus::Incomplete,
        };
    }
}
