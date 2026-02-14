<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::query()->updateOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'description' => 'Get started with basic features',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'limits' => [
                    'workflows' => 5,
                    'executions' => 500,
                    'members' => 1,
                ],
                'features' => [
                    'webhooks' => false,
                    'priority_support' => false,
                ],
                'is_active' => true,
                'sort_order' => 0,
            ]
        );

        Plan::query()->updateOrCreate(
            ['slug' => 'pro'],
            [
                'name' => 'Pro',
                'description' => 'For growing teams and businesses',
                'price_monthly' => 1900,
                'price_yearly' => 19000,
                'limits' => [
                    'workflows' => 50,
                    'executions' => 10000,
                    'members' => 5,
                ],
                'features' => [
                    'webhooks' => true,
                    'priority_support' => false,
                ],
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        Plan::query()->updateOrCreate(
            ['slug' => 'business'],
            [
                'name' => 'Business',
                'description' => 'For large teams with advanced needs',
                'price_monthly' => 4900,
                'price_yearly' => 49000,
                'limits' => [
                    'workflows' => -1,
                    'executions' => 100000,
                    'members' => 20,
                ],
                'features' => [
                    'webhooks' => true,
                    'priority_support' => true,
                ],
                'is_active' => true,
                'sort_order' => 2,
            ]
        );
    }
}
