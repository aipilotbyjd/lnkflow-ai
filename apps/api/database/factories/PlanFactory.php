<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'slug' => fake()->unique()->slug(),
            'description' => fake()->sentence(),
            'price_monthly' => fake()->randomElement([0, 1900, 4900]),
            'price_yearly' => fake()->randomElement([0, 19000, 49000]),
            'limits' => [
                'workflows' => fake()->randomElement([5, 50, -1]),
                'executions' => fake()->randomElement([500, 10000, 100000]),
                'members' => fake()->randomElement([1, 5, 20]),
            ],
            'features' => [
                'webhooks' => fake()->boolean(),
                'priority_support' => fake()->boolean(),
            ],
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
