<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditMeterService;
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

describe('billing.show', function () {
    it('returns billing info with no subscription', function () {
        $this->mock(CreditMeterService::class, function ($mock) {
            $mock->shouldReceive('usage')->andReturn([
                'credits_used' => 0,
                'credits_limit' => 0,
            ]);
            $mock->shouldReceive('currentPeriod')->andReturn(null);
        });

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/billing");

        $response->assertSuccessful()
            ->assertJsonPath('subscription', null)
            ->assertJsonPath('has_active_subscription', false);
    });

    it('returns billing info with active subscription', function () {
        $plan = Plan::factory()->create();
        Subscription::factory()->create([
            'workspace_id' => $this->workspace->id,
            'plan_id' => $plan->id,
        ]);

        $this->mock(CreditMeterService::class, function ($mock) {
            $mock->shouldReceive('usage')->andReturn([
                'credits_used' => 100,
                'credits_limit' => 10000,
            ]);
            $mock->shouldReceive('currentPeriod')->andReturn(null);
        });

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/billing");

        $response->assertSuccessful()
            ->assertJsonPath('has_active_subscription', true)
            ->assertJsonStructure([
                'subscription',
                'usage',
                'current_period',
                'has_active_subscription',
            ]);
    });
});

describe('billing.checkout', function () {
    it('validates plan_id is required for checkout', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/billing/checkout", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_id']);
    });

    it('validates billing_interval values', function () {
        $plan = Plan::factory()->create();

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/billing/checkout", [
            'plan_id' => $plan->id,
            'billing_interval' => 'invalid',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['billing_interval']);
    });
});

describe('billing.cancel', function () {
    it('returns 404 when no subscription to cancel', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/billing/cancel");

        $response->assertNotFound();
    });
});

describe('billing.change-plan', function () {
    it('validates plan_id is required', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/billing/change-plan", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_id']);
    });

    it('validates billing_interval values for change-plan', function () {
        $plan = Plan::factory()->create();

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/billing/change-plan", [
            'plan_id' => $plan->id,
            'billing_interval' => 'weekly',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['billing_interval']);
    });
});

describe('billing authorization', function () {
    it('returns forbidden for non-member', function () {
        $otherUser = User::factory()->create();
        Passport::actingAs($otherUser);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/billing");

        $response->assertForbidden();
    });
});
