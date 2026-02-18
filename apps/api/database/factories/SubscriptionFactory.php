<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'plan_id' => Plan::factory(),
            'stripe_subscription_id' => null,
            'stripe_customer_id' => null,
            'stripe_price_id' => null,
            'status' => SubscriptionStatus::Active,
            'billing_interval' => 'monthly',
            'credits_monthly' => 0,
            'credits_yearly_pool' => 0,
            'trial_ends_at' => null,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'canceled_at' => null,
        ];
    }

    public function trialing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function canceled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Canceled,
            'canceled_at' => now(),
        ]);
    }

    public function withStripe(): static
    {
        return $this->state(fn (array $attributes) => [
            'stripe_subscription_id' => 'sub_' . fake()->regexify('[A-Za-z0-9]{24}'),
            'stripe_customer_id' => 'cus_' . fake()->regexify('[A-Za-z0-9]{14}'),
            'stripe_price_id' => 'price_' . fake()->regexify('[A-Za-z0-9]{24}'),
        ]);
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => [
            'billing_interval' => 'yearly',
            'current_period_end' => now()->addYear(),
        ]);
    }
}
