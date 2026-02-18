<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, [
        'role' => 'owner',
        'joined_at' => now(),
    ]);
    Passport::actingAs($this->user);
});

describe('subscription.show', function () {
    it('returns null when no subscription exists', function () {
        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/subscription");

        $response->assertSuccessful()
            ->assertJsonPath('subscription', null);
    });

    it('returns subscription with plan when exists', function () {
        $plan = Plan::factory()->create();
        Subscription::factory()->create([
            'workspace_id' => $this->workspace->id,
            'plan_id' => $plan->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/subscription");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'subscription' => [
                    'id',
                    'workspace_id',
                    'plan',
                    'status',
                    'current_period_start',
                    'current_period_end',
                ],
            ]);
    });
});

describe('subscription.store', function () {
    it('creates a subscription for workspace', function () {
        $plan = Plan::factory()->create();

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/subscription", [
            'plan_id' => $plan->id,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('subscription.plan.id', $plan->id);

        $this->assertDatabaseHas('subscriptions', [
            'workspace_id' => $this->workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
    });

    it('requires plan_id', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/subscription", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_id']);
    });

    it('rejects invalid plan_id', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/subscription", [
            'plan_id' => 99999,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_id']);
    });
});

describe('subscription.destroy', function () {
    it('cancels an active subscription', function () {
        $plan = Plan::factory()->create();
        Subscription::factory()->create([
            'workspace_id' => $this->workspace->id,
            'plan_id' => $plan->id,
        ]);

        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/subscription");

        $response->assertSuccessful();
        $this->assertDatabaseHas('subscriptions', [
            'workspace_id' => $this->workspace->id,
            'status' => 'canceled',
        ]);
    });

    it('returns 404 when no subscription exists', function () {
        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/subscription");

        $response->assertNotFound();
    });
});

describe('subscription authorization', function () {
    it('returns forbidden for non-member', function () {
        $otherUser = User::factory()->create();
        Passport::actingAs($otherUser);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/subscription");

        $response->assertForbidden();
    });
});
