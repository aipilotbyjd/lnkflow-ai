<?php

namespace Database\Factories;

use App\Enums\WebhookAuthType;
use App\Enums\WebhookResponseMode;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Webhook>
 */
class WebhookFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'workspace_id' => fn (array $attributes) => Workflow::find($attributes['workflow_id'])?->workspace_id ?? Workspace::factory(),
            'uuid' => Str::uuid()->toString(),
            'path' => null,
            'methods' => ['POST'],
            'is_active' => true,
            'auth_type' => WebhookAuthType::None,
            'auth_config' => null,
            'rate_limit' => null,
            'response_mode' => WebhookResponseMode::Immediate,
            'response_status' => 200,
            'response_body' => ['success' => true],
            'call_count' => 0,
            'last_called_at' => null,
        ];
    }

    public function withPath(?string $path = null): static
    {
        return $this->state(fn () => [
            'path' => $path ?? $this->faker->slug(2),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function withHeaderAuth(): static
    {
        return $this->state(fn () => [
            'auth_type' => WebhookAuthType::Header,
            'auth_config' => [
                'header_name' => 'X-Webhook-Secret',
                'header_value' => Str::random(32),
            ],
        ]);
    }

    public function withBasicAuth(): static
    {
        return $this->state(fn () => [
            'auth_type' => WebhookAuthType::Basic,
            'auth_config' => [
                'username' => $this->faker->userName(),
                'password' => Str::random(16),
            ],
        ]);
    }

    public function withBearerAuth(): static
    {
        return $this->state(fn () => [
            'auth_type' => WebhookAuthType::Bearer,
            'auth_config' => [
                'token' => Str::random(64),
            ],
        ]);
    }

    public function withRateLimit(int $limit = 60): static
    {
        return $this->state(fn () => [
            'rate_limit' => $limit,
        ]);
    }

    public function waitMode(): static
    {
        return $this->state(fn () => [
            'response_mode' => WebhookResponseMode::Wait,
        ]);
    }

    public function withMethods(array $methods): static
    {
        return $this->state(fn () => [
            'methods' => $methods,
        ]);
    }
}
