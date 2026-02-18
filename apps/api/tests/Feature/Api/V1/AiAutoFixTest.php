<?php

use App\Models\AiFixSuggestion;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
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

describe('analyze', function () {
    it('analyzes a failed execution', function () {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'diagnosis' => 'The HTTP request node has an invalid URL.',
                                'suggestions' => [
                                    [
                                        'description' => 'Fix the URL in the HTTP request node.',
                                        'confidence' => 0.87,
                                        'patch' => ['nodes' => [], 'edges' => []],
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
                'usage' => ['total_tokens' => 200],
            ], 200),
        ]);

        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $execution = Execution::factory()->create([
            'workspace_id' => $this->workspace->id,
            'workflow_id' => $workflow->id,
            'status' => 'failed',
            'error' => ['message' => 'Connection refused'],
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/ai/analyze");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'message',
                'diagnosis',
                'suggestions',
                'fix_suggestion',
            ]);

        $this->assertDatabaseHas('ai_fix_suggestions', [
            'execution_id' => $execution->id,
            'workspace_id' => $this->workspace->id,
        ]);
    });

    it('rejects analysis of non-failed execution', function () {
        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $execution = Execution::factory()->create([
            'workspace_id' => $this->workspace->id,
            'workflow_id' => $workflow->id,
            'status' => 'completed',
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/ai/analyze");

        $response->assertStatus(422);
    });

    it('returns forbidden for non-member', function () {
        $otherUser = User::factory()->create();
        Passport::actingAs($otherUser);

        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $execution = Execution::factory()->create([
            'workspace_id' => $this->workspace->id,
            'workflow_id' => $workflow->id,
            'status' => 'failed',
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/ai/analyze");

        $response->assertForbidden();
    });
});

describe('apply fix', function () {
    it('applies a fix suggestion', function () {
        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $execution = Execution::factory()->create([
            'workspace_id' => $this->workspace->id,
            'workflow_id' => $workflow->id,
            'status' => 'failed',
        ]);

        AiFixSuggestion::factory()->create([
            'workspace_id' => $this->workspace->id,
            'execution_id' => $execution->id,
            'workflow_id' => $workflow->id,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/ai/apply-fix", [
            'suggestion_index' => 0,
        ]);

        $response->assertSuccessful()
            ->assertJsonStructure([
                'message',
                'workflow_version',
                'applied_patch',
            ]);
    });
});

describe('fix history', function () {
    it('returns paginated fix history', function () {
        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        AiFixSuggestion::factory()->count(3)->create([
            'workspace_id' => $this->workspace->id,
            'workflow_id' => $workflow->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/ai/fix-history");

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data');
    });

    it('filters by workflow_id', function () {
        $workflow1 = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);
        $workflow2 = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        AiFixSuggestion::factory()->count(2)->create([
            'workspace_id' => $this->workspace->id,
            'workflow_id' => $workflow1->id,
        ]);
        AiFixSuggestion::factory()->create([
            'workspace_id' => $this->workspace->id,
            'workflow_id' => $workflow2->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/ai/fix-history?workflow_id={$workflow1->id}");

        $response->assertSuccessful()
            ->assertJsonCount(2, 'data');
    });
});
