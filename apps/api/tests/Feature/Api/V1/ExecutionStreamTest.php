<?php

use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
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

describe('stream', function () {
    it('returns SSE response with correct headers', function () {
        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $execution = Execution::factory()->create([
            'workspace_id' => $this->workspace->id,
            'workflow_id' => $workflow->id,
            'status' => 'running',
        ]);

        $response = $this->get(
            "/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/stream",
            ['Accept' => 'text/event-stream']
        );

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'text/event-stream');
        $response->assertHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
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
            'status' => 'running',
        ]);

        $response = $this->get(
            "/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/stream",
            ['Accept' => 'text/event-stream']
        );

        $response->assertForbidden();
    });

    it('returns not found for execution in different workspace', function () {
        $otherWorkspace = Workspace::factory()->create();
        $workflow = Workflow::factory()->create([
            'workspace_id' => $otherWorkspace->id,
            'created_by' => $this->user->id,
        ]);

        $execution = Execution::factory()->create([
            'workspace_id' => $otherWorkspace->id,
            'workflow_id' => $workflow->id,
            'status' => 'running',
        ]);

        $response = $this->get(
            "/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/stream",
            ['Accept' => 'text/event-stream']
        );

        $response->assertNotFound();
    });
});
