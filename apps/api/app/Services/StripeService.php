<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;
use Stripe\Webhook;

class StripeService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Create or get Stripe customer for a workspace.
     */
    public function getOrCreateCustomer(Workspace $workspace): Customer
    {
        $subscription = $workspace->subscription;

        if ($subscription && $subscription->stripe_customer_id) {
            try {
                return Customer::retrieve($subscription->stripe_customer_id);
            } catch (ApiErrorException $e) {
                Log::warning('Failed to retrieve Stripe customer', [
                    'workspace_id' => $workspace->id,
                    'customer_id' => $subscription->stripe_customer_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Create new customer
        $owner = $workspace->owner;

        $customer = Customer::create([
            'email' => $owner->email,
            'name' => $owner->full_name,
            'metadata' => [
                'workspace_id' => $workspace->id,
                'workspace_name' => $workspace->name,
            ],
        ]);

        // Save customer ID
        if ($subscription) {
            $subscription->update(['stripe_customer_id' => $customer->id]);
        }

        return $customer;
    }

    /**
     * Create a checkout session for subscription.
     */
    public function createCheckoutSession(
        Workspace $workspace,
        Plan $plan,
        string $billingInterval = 'monthly',
        ?string $successUrl = null,
        ?string $cancelUrl = null
    ): CheckoutSession {
        $customer = $this->getOrCreateCustomer($workspace);

        $priceId = $billingInterval === 'yearly'
            ? $plan->stripe_price_yearly_id
            : $plan->stripe_price_monthly_id;

        if (! $priceId) {
            throw new \InvalidArgumentException("Plan does not have a Stripe price ID for {$billingInterval} billing");
        }

        $session = CheckoutSession::create([
            'customer' => $customer->id,
            'mode' => 'subscription',
            'line_items' => [
                [
                    'price' => $priceId,
                    'quantity' => 1,
                ],
            ],
            'success_url' => $successUrl ?? config('app.frontend_url').'/billing?success=true',
            'cancel_url' => $cancelUrl ?? config('app.frontend_url').'/billing?cancelled=true',
            'subscription_data' => [
                'metadata' => [
                    'workspace_id' => $workspace->id,
                    'plan_id' => $plan->id,
                ],
            ],
            'metadata' => [
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
            ],
            'allow_promotion_codes' => true,
        ]);

        return $session;
    }

    /**
     * Create a billing portal session for managing subscription.
     */
    public function createBillingPortalSession(Workspace $workspace, ?string $returnUrl = null): BillingPortalSession
    {
        $customer = $this->getOrCreateCustomer($workspace);

        return BillingPortalSession::create([
            'customer' => $customer->id,
            'return_url' => $returnUrl ?? config('app.frontend_url').'/billing',
        ]);
    }

    /**
     * Cancel a subscription.
     */
    public function cancelSubscription(Subscription $subscription, bool $immediately = false): void
    {
        if (! $subscription->stripe_subscription_id) {
            throw new \InvalidArgumentException('No Stripe subscription ID found');
        }

        $stripeSubscription = StripeSubscription::retrieve($subscription->stripe_subscription_id);

        if ($immediately) {
            $stripeSubscription->cancel();
            $subscription->update([
                'status' => SubscriptionStatus::Canceled,
                'canceled_at' => now(),
            ]);
        } else {
            // Cancel at period end
            $stripeSubscription->update([
                'cancel_at_period_end' => true,
            ]);
            $subscription->update([
                'canceled_at' => now(),
            ]);
        }
    }

    /**
     * Resume a canceled subscription.
     */
    public function resumeSubscription(Subscription $subscription): void
    {
        if (! $subscription->stripe_subscription_id) {
            throw new \InvalidArgumentException('No Stripe subscription ID found');
        }

        $stripeSubscription = StripeSubscription::retrieve($subscription->stripe_subscription_id);
        $stripeSubscription->update([
            'cancel_at_period_end' => false,
        ]);

        $subscription->update([
            'canceled_at' => null,
            'status' => SubscriptionStatus::Active,
        ]);
    }

    /**
     * Change subscription plan.
     */
    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingInterval = 'monthly'): void
    {
        if (! $subscription->stripe_subscription_id) {
            throw new \InvalidArgumentException('No Stripe subscription ID found');
        }

        $priceId = $billingInterval === 'yearly'
            ? $newPlan->stripe_price_yearly_id
            : $newPlan->stripe_price_monthly_id;

        $stripeSubscription = StripeSubscription::retrieve($subscription->stripe_subscription_id);

        StripeSubscription::update($subscription->stripe_subscription_id, [
            'items' => [
                [
                    'id' => $stripeSubscription->items->data[0]->id,
                    'price' => $priceId,
                ],
            ],
            'proration_behavior' => 'create_prorations',
        ]);

        $subscription->update([
            'plan_id' => $newPlan->id,
        ]);
    }

    /**
     * Validate webhook signature.
     */
    public function constructWebhookEvent(string $payload, string $signature): \Stripe\Event
    {
        return Webhook::constructEvent(
            $payload,
            $signature,
            config('services.stripe.webhook_secret')
        );
    }

    /**
     * Get subscription details from Stripe.
     */
    public function getSubscription(string $subscriptionId): StripeSubscription
    {
        return StripeSubscription::retrieve([
            'id' => $subscriptionId,
            'expand' => ['latest_invoice', 'customer'],
        ]);
    }
}
