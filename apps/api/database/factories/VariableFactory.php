<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Variable;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Variable>
 */
class VariableFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Generate a valid key: starts with uppercase, uppercase letters, numbers, underscores only
        $words = ['API', 'BASE', 'SECRET', 'AUTH', 'DATABASE', 'CACHE', 'REDIS', 'APP', 'CONFIG', 'SERVICE'];
        $suffixes = ['KEY', 'URL', 'TOKEN', 'HOST', 'PORT', 'NAME', 'VALUE', 'PATH', 'ID', 'SECRET'];

        $key = $this->faker->randomElement($words).'_'.$this->faker->randomElement($suffixes).'_'.$this->faker->unique()->randomNumber(4);

        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'key' => $key,
            'value' => $this->faker->sentence(),
            'description' => $this->faker->optional()->sentence(),
            'is_secret' => false,
        ];
    }

    public function secret(): static
    {
        return $this->state(fn () => [
            'is_secret' => true,
        ])->afterCreating(function (Variable $variable) {
            // Re-set value after is_secret is true to trigger encryption
            $variable->value = $this->faker->sha256();
            $variable->save();
        });
    }

    public function withKey(string $key): static
    {
        return $this->state(fn () => [
            'key' => $key,
        ]);
    }
}
