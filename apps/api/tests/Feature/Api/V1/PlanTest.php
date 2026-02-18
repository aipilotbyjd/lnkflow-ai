<?php

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('plans.index', function () {
    it('returns active plans sorted by sort_order', function () {
        Plan::factory()->create(['name' => 'Pro', 'sort_order' => 2, 'is_active' => true]);
        Plan::factory()->create(['name' => 'Free', 'sort_order' => 0, 'is_active' => true]);
        Plan::factory()->create(['name' => 'Hidden', 'sort_order' => 3, 'is_active' => false]);

        $response = $this->getJson('/api/v1/plans');

        $response->assertSuccessful()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Free')
            ->assertJsonPath('data.1.name', 'Pro');
    });

    it('includes plan limits features and credit_tiers in response', function () {
        Plan::factory()->create([
            'is_active' => true,
            'credit_tiers' => [10000, 20000],
        ]);

        $response = $this->getJson('/api/v1/plans');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'description',
                        'price_monthly',
                        'price_yearly',
                        'limits',
                        'features',
                        'credit_tiers',
                    ],
                ],
            ]);
    });

    it('returns empty collection when no active plans exist', function () {
        Plan::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/v1/plans');

        $response->assertSuccessful()
            ->assertJsonCount(0, 'data');
    });
});
