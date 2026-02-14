<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Credential>
 */
class CredentialFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'name' => $this->faker->unique()->words(3, true),
            'type' => 'api_key',
            'data' => ['api_key' => $this->faker->sha256(), 'header_name' => 'X-API-Key'],
            'last_used_at' => null,
            'expires_at' => null,
        ];
    }

    public function apiKey(): static
    {
        return $this->state(fn () => [
            'type' => 'api_key',
            'data' => ['api_key' => $this->faker->sha256(), 'header_name' => 'X-API-Key'],
        ]);
    }

    public function bearerToken(): static
    {
        return $this->state(fn () => [
            'type' => 'bearer_token',
            'data' => ['token' => $this->faker->sha256()],
        ]);
    }

    public function basicAuth(): static
    {
        return $this->state(fn () => [
            'type' => 'basic_auth',
            'data' => ['username' => $this->faker->userName(), 'password' => $this->faker->password()],
        ]);
    }

    public function slack(): static
    {
        return $this->state(fn () => [
            'type' => 'slack',
            'data' => ['bot_token' => 'xoxb-'.$this->faker->sha256()],
        ]);
    }

    public function github(): static
    {
        return $this->state(fn () => [
            'type' => 'github',
            'data' => ['token' => 'ghp_'.$this->faker->sha256()],
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function expiresIn(int $days): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->addDays($days),
        ]);
    }
}
