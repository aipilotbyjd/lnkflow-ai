<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $actions = [
            'workflow.created',
            'workflow.updated',
            'workflow.deleted',
            'credential.created',
            'credential.updated',
            'variable.created',
            'tag.created',
        ];

        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'action' => $this->faker->randomElement($actions),
            'description' => $this->faker->sentence(),
            'subject_type' => null,
            'subject_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
        ];
    }

    public function forSubject(string $type, int $id): static
    {
        return $this->state(fn () => [
            'subject_type' => $type,
            'subject_id' => $id,
        ]);
    }

    public function withChanges(array $old, array $new): static
    {
        return $this->state(fn () => [
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }
}
