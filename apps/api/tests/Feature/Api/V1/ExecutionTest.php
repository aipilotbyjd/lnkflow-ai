<?php

use App\Enums\ExecutionMode;
use App\Enums\ExecutionStatus;
use App\Enums\LogLevel;
use App\Jobs\ExecuteWorkflowJob;
use App\Models\Execution;
use App\Models\ExecutionLog;
use App\Models\ExecutionNode;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, [
        'role' => 'owner',
        'joined_at' => now(),
    ]);
    $this->workflow = Workflow::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
    ]);
    Passport::actingAs($this->user);
});

describe('index', function () {
    it('returns paginated executions for workspace', function () {
        Execution::factory()->count(3)->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
            'triggered_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/executions");

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'workflow',
                        'status',
                        'mode',
                        'started_at',
                        'finished_at',
                        'duration_ms',
                    ],
                ],
            ]);
    });

    it('filters by status', function () {
        Execution::factory()->completed()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);
        Execution::factory()->failed()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/executions?status=completed");

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    });

    it('filters by workflow_id', function () {
        $otherWorkflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        Execution::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);
        Execution::factory()->create([
            'workflow_id' => $otherWorkflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/executions?workflow_id={$this->workflow->id}");

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    });

    it('returns forbidden for non-member', function () {
        $otherUser = User::factory()->create();
        Passport::actingAs($otherUser);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/executions");

        $response->assertForbidden();
    });
});

describe('show', function () {
    it('returns a single execution with nodes', function () {
        $execution = Execution::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        ExecutionNode::factory()->count(2)->create([
            'execution_id' => $execution->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}");

        $response->assertSuccessful()
            ->assertJsonPath('execution.id', $execution->id)
            ->assertJsonCount(2, 'execution.nodes');
    });

    it('returns not found for execution in different workspace', function () {
        $otherWorkspace = Workspace::factory()->create();
        $execution = Execution::factory()->create([
            'workspace_id' => $otherWorkspace->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}");

        $response->assertNotFound();
    });
});

describe('destroy', function () {
    it('deletes an execution', function () {
        $execution = Execution::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}");

        $response->assertSuccessful();

        $this->assertDatabaseMissing('executions', ['id' => $execution->id]);
    });
});

describe('nodes', function () {
    it('returns execution nodes', function () {
        $execution = Execution::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        ExecutionNode::factory()->count(3)->create([
            'execution_id' => $execution->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/nodes");

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'node_id',
                        'node_type',
                        'status',
                        'duration_ms',
                    ],
                ],
            ]);
    });
});

describe('logs', function () {
    it('returns execution logs', function () {
        $execution = Execution::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        ExecutionLog::create([
            'execution_id' => $execution->id,
            'level' => LogLevel::Info,
            'message' => 'Test log message',
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/logs");

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'Test log message');
    });

    it('filters logs by level', function () {
        $execution = Execution::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        ExecutionLog::create([
            'execution_id' => $execution->id,
            'level' => LogLevel::Info,
            'message' => 'Info message',
        ]);
        ExecutionLog::create([
            'execution_id' => $execution->id,
            'level' => LogLevel::Error,
            'message' => 'Error message',
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/logs?level=error");

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'Error message');
    });
});

describe('retry', function () {
    it('creates a retry execution for failed execution', function () {
        Queue::fake();

        $execution = Execution::factory()->failed()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
            'attempt' => 1,
            'max_attempts' => 3,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/retry");

        $response->assertCreated()
            ->assertJsonPath('execution.attempt', 2)
            ->assertJsonPath('execution.parent_execution_id', $execution->id)
            ->assertJsonPath('execution.mode', ExecutionMode::Retry->value);

        $this->assertDatabaseHas('executions', [
            'parent_execution_id' => $execution->id,
            'attempt' => 2,
        ]);

        Queue::assertPushed(ExecuteWorkflowJob::class);
    });

    it('returns error for completed execution', function () {
        $execution = Execution::factory()->completed()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/retry");

        $response->assertUnprocessable();
    });

    it('returns error when max attempts reached', function () {
        $execution = Execution::factory()->failed()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
            'attempt' => 3,
            'max_attempts' => 3,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/retry");

        $response->assertUnprocessable();
    });
});

describe('cancel', function () {
    it('cancels a running execution', function () {
        $execution = Execution::factory()->running()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/cancel");

        $response->assertSuccessful()
            ->assertJsonPath('execution.status', ExecutionStatus::Cancelled->value);

        $this->assertDatabaseHas('executions', [
            'id' => $execution->id,
            'status' => ExecutionStatus::Cancelled->value,
        ]);
    });

    it('cancels a pending execution', function () {
        $execution = Execution::factory()->pending()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/cancel");

        $response->assertSuccessful()
            ->assertJsonPath('execution.status', ExecutionStatus::Cancelled->value);
    });

    it('returns error for completed execution', function () {
        $execution = Execution::factory()->completed()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/cancel");

        $response->assertUnprocessable();
    });
});

describe('workflowExecutions', function () {
    it('returns executions for a specific workflow', function () {
        $otherWorkflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        Execution::factory()->count(2)->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);
        Execution::factory()->create([
            'workflow_id' => $otherWorkflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}/executions");

        $response->assertSuccessful()
            ->assertJsonCount(2, 'data');
    });
});

describe('stats', function () {
    it('returns execution statistics', function () {
        Execution::factory()->completed()->count(3)->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);
        Execution::factory()->failed()->count(1)->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);
        Execution::factory()->running()->count(1)->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/executions/stats");

        $response->assertSuccessful()
            ->assertJsonPath('stats.total', 5)
            ->assertJsonPath('stats.completed', 3)
            ->assertJsonPath('stats.failed', 1)
            ->assertJsonPath('stats.running', 1)
            ->assertJsonPath('stats.success_rate', 60);
    });

    it('filters stats by workflow_id', function () {
        $otherWorkflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        Execution::factory()->completed()->count(2)->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);
        Execution::factory()->completed()->count(3)->create([
            'workflow_id' => $otherWorkflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/executions/stats?workflow_id={$this->workflow->id}");

        $response->assertSuccessful()
            ->assertJsonPath('stats.total', 2);
    });
});

describe('permissions', function () {
    it('allows viewer to view executions', function () {
        $viewer = User::factory()->create();
        $this->workspace->members()->attach($viewer->id, [
            'role' => 'viewer',
            'joined_at' => now(),
        ]);
        Passport::actingAs($viewer);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/executions");

        $response->assertSuccessful();
    });

    it('forbids viewer from retrying executions', function () {
        $viewer = User::factory()->create();
        $this->workspace->members()->attach($viewer->id, [
            'role' => 'viewer',
            'joined_at' => now(),
        ]);
        Passport::actingAs($viewer);

        $execution = Execution::factory()->failed()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
            'max_attempts' => 3,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/retry");

        $response->assertForbidden();
    });
});
