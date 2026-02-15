<?php

namespace App\Http\Controllers\Api;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class StripeWebhookController extends Controller
{
    public function __construct(
        private StripeService $stripeService
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

        // Update or create subscription
        $subscription = Subscription::updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'stripe_subscription_id' => $session->subscription,
                'stripe_customer_id' => $session->customer,
                'plan_id' => $planId,
                'status' => SubscriptionStatus::Active,
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]
        );

        Log::info('Subscription created from checkout', [
            'workspace_id' => $workspaceId,
            'subscription_id' => $subscription->id,
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

        Subscription::updateOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'stripe_subscription_id' => $stripeSubscription->id,
                'stripe_customer_id' => $stripeSubscription->customer,
                'plan_id' => $planId,
                'status' => $this->mapStripeStatus($stripeSubscription->status),
                'current_period_start' => now()->setTimestamp($stripeSubscription->current_period_start),
                'current_period_end' => now()->setTimestamp($stripeSubscription->current_period_end),
                'trial_ends_at' => $stripeSubscription->trial_end
                    ? now()->setTimestamp($stripeSubscription->trial_end)
                    : null,
            ]
        );

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

        // Check if plan changed
        $priceId = $stripeSubscription->items->data[0]->price->id ?? null;
        if ($priceId) {
            $plan = Plan::where('stripe_price_monthly_id', $priceId)
                ->orWhere('stripe_price_yearly_id', $priceId)
                ->first();

            if ($plan) {
                $subscription->plan_id = $plan->id;
            }
        }

        $subscription->update([
            'status' => $this->mapStripeStatus($stripeSubscription->status),
            'current_period_start' => now()->setTimestamp($stripeSubscription->current_period_start),
            'current_period_end' => now()->setTimestamp($stripeSubscription->current_period_end),
            'canceled_at' => $stripeSubscription->canceled_at
                ? now()->setTimestamp($stripeSubscription->canceled_at)
                : null,
        ]);

        Log::info('Subscription updated', [
            'subscription_id' => $subscription->id,
            'status' => $subscription->status,
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

        $subscription = Subscription::where('stripe_subscription_id', $invoice->subscription)->first();

        if ($subscription) {
            $subscription->update([
                'status' => SubscriptionStatus::Active,
            ]);

            Log::info('Invoice paid', [
                'subscription_id' => $subscription->id,
                'amount' => $invoice->amount_paid / 100,
            ]);
        }

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
