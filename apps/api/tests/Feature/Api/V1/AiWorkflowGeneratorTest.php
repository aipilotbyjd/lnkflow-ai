<?php

use App\Models\AiGenerationLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

describe('generate', function () {
    it('generates a workflow from a prompt', function () {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'workflow' => [
                                    'name' => 'Slack Notification Workflow',
                                    'description' => 'Sends a notification to Slack',
                                    'trigger_type' => 'manual',
                                    'trigger_config' => [],
                                    'nodes' => [
                                        [
                                            'id' => 'trigger_1',
                                            'type' => 'trigger_manual',
                                            'position' => ['x' => 100, 'y' => 200],
                                            'data' => ['label' => 'Manual Trigger'],
                                        ],
                                    ],
                                    'edges' => [],
                                ],
                                'explanation' => 'This workflow sends a Slack notification.',
                                'confidence' => 0.92,
                            ]),
                        ],
                    ],
                ],
                'usage' => ['total_tokens' => 150],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/ai/generate-workflow", [
            'prompt' => 'Create a workflow that sends a Slack notification when triggered manually',
            'options' => ['dry_run' => true],
        ]);

        $response->assertSuccessful()
            ->assertJsonStructure([
                'message',
                'workflow',
                'explanation',
                'confidence',
                'generation_log',
            ]);

        $this->assertDatabaseHas('ai_generation_logs', [
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
        ]);
    });

    it('creates workflow when not dry run', function () {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'workflow' => [
                                    'name' => 'Test Workflow',
                                    'description' => 'A test workflow',
                                    'trigger_type' => 'manual',
                                    'trigger_config' => [],
                                    'nodes' => [
                                        [
                                            'id' => 'trigger_1',
                                            'type' => 'trigger_manual',
                                            'position' => ['x' => 100, 'y' => 200],
                                            'data' => ['label' => 'Manual Trigger'],
                                        ],
                                    ],
                                    'edges' => [],
                                ],
                                'explanation' => 'A basic manual workflow.',
                                'confidence' => 0.9,
                            ]),
                        ],
                    ],
                ],
                'usage' => ['total_tokens' => 120],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/ai/generate-workflow", [
            'prompt' => 'Create a simple manual workflow for testing purposes',
        ]);

        $response->assertCreated()
            ->assertJsonPath('workflow.name', 'Test Workflow');

        $this->assertDatabaseHas('workflows', [
            'workspace_id' => $this->workspace->id,
            'name' => 'Test Workflow',
        ]);
    });

    it('validates prompt is required', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/ai/generate-workflow", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt']);
    });

    it('validates prompt minimum length', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/ai/generate-workflow", [
            'prompt' => 'short',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt']);
    });

    it('returns forbidden for non-member', function () {
        $otherUser = User::factory()->create();
        Passport::actingAs($otherUser);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/ai/generate-workflow", [
            'prompt' => 'Create a workflow that sends emails automatically',
        ]);

        $response->assertForbidden();
    });
});

describe('refine', function () {
    it('refines a generated workflow', function () {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'workflow' => [
                                    'name' => 'Refined Workflow',
                                    'trigger_type' => 'manual',
                                    'trigger_config' => [],
                                    'nodes' => [],
                                    'edges' => [],
                                ],
                                'changes' => ['Added error handling node'],
                            ]),
                        ],
                    ],
                ],
                'usage' => ['total_tokens' => 100],
            ], 200),
        ]);

        $log = AiGenerationLog::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/ai/refine-workflow", [
            'generation_log_id' => $log->id,
            'feedback' => 'Add error handling to the workflow',
        ]);

        $response->assertSuccessful()
            ->assertJsonStructure(['message', 'workflow', 'changes', 'generation_log']);
    });
});

describe('generation history', function () {
    it('returns paginated generation history', function () {
        AiGenerationLog::factory()->count(3)->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/ai/generation-history");

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data');
    });

    it('deletes a generation log', function () {
        $log = AiGenerationLog::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/ai/generation-history/{$log->id}");

        $response->assertSuccessful();
        $this->assertDatabaseMissing('ai_generation_logs', ['id' => $log->id]);
    });

    it('returns forbidden for non-member', function () {
        $otherUser = User::factory()->create();
        Passport::actingAs($otherUser);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/ai/generation-history");

        $response->assertForbidden();
    });
});
