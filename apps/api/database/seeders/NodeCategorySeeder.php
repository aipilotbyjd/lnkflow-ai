<?php

namespace Database\Seeders;

use App\Models\NodeCategory;
use Illuminate\Database\Seeder;

class NodeCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Triggers',
                'slug' => 'triggers',
                'icon' => 'bolt',
                'color' => '#f59e0b',
                'description' => 'Start your workflow',
                'sort_order' => 1,
            ],
            [
                'name' => 'HTTP & APIs',
                'slug' => 'http',
                'icon' => 'globe',
                'color' => '#3b82f6',
                'description' => 'Make HTTP requests and call APIs',
                'sort_order' => 2,
            ],
            [
                'name' => 'Communication',
                'slug' => 'communication',
                'icon' => 'chat-bubble-left-right',
                'color' => '#8b5cf6',
                'description' => 'Email, Slack, SMS and more',
                'sort_order' => 3,
            ],
            [
                'name' => 'Data',
                'slug' => 'data',
                'icon' => 'circle-stack',
                'color' => '#10b981',
                'description' => 'Transform and store data',
                'sort_order' => 4,
            ],
            [
                'name' => 'Logic',
                'slug' => 'logic',
                'icon' => 'code-bracket',
                'color' => '#6366f1',
                'description' => 'Conditions, loops, and delays',
                'sort_order' => 5,
            ],
            [
                'name' => 'Integrations',
                'slug' => 'integrations',
                'icon' => 'puzzle-piece',
                'color' => '#ec4899',
                'description' => 'Third-party services',
                'sort_order' => 6,
            ],
        ];

        foreach ($categories as $category) {
            NodeCategory::query()->updateOrCreate(
                ['slug' => $category['slug']],
                $category
            );
        }
    }
}
