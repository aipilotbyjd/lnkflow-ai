<?php

namespace Database\Factories;

use App\Models\CreditPack;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditPack>
 */
class CreditPackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $credits = fake()->randomElement([10000, 20000, 40000, 80000]);

        return [
            'workspace_id' => Workspace::factory(),
            'purchased_by' => User::factory(),
            'credits_amount' => $credits,
            'credits_remaining' => $credits,
            'price_cents' => $credits / 10,
            'currency' => 'usd',
            'stripe_payment_intent_id' => null,
            'stripe_invoice_id' => null,
            'status' => 'active',
            'purchased_at' => now(),
            'expires_at' => null,
        ];
    }

    public function exhausted(): static
    {
        return $this->state(fn (array $attributes) => [
            'credits_remaining' => 0,
            'status' => 'exhausted',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'expires_at' => now()->subDay(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }
}
