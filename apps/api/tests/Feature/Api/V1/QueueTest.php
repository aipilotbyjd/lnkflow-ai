<?php

use App\Jobs\ExecuteWorkflowJob;
use App\Models\Execution;
use App\Models\JobStatus;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\WorkflowDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

function engineHeaders(array $payload, ?string $secret = 'test-engine-secret'): array
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = now()->toRfc3339String();

    return [
        'X-LinkFlow-Timestamp' => $timestamp,
        'X-LinkFlow-Signature' => hash_hmac('sha256', $body, $secret ?? ''),
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, [
        'role' => 'owner',
        'joined_at' => now(),
    ]);

    config([
        'services.engine.secret' => 'test-engine-secret',
        'services.engine.callback_ttl' => 300,
    ]);

    Passport::actingAs($this->user);
});

describe('JobStatus model', function () {
    it('creates a job status', function () {
        $status = JobStatus::factory()->create([
            'execution_id' => null,
        ]);

        expect($status->status)->toBe('pending');
        expect($status->progress)->toBe(0);
    });

    it('marks job as processing', function () {
        $status = JobStatus::factory()->create();

        $status->markProcessing();

        expect($status->fresh()->status)->toBe('processing');
        expect($status->fresh()->started_at)->not->toBeNull();
    });

    it('marks job as completed', function () {
        $status = JobStatus::factory()->processing()->create();

        $status->markCompleted(['duration_ms' => 1000]);

        expect($status->fresh()->status)->toBe('completed');
        expect($status->fresh()->progress)->toBe(100);
        expect($status->fresh()->result['duration_ms'])->toBe(1000);
    });

    it('marks job as failed', function () {
        $status = JobStatus::factory()->processing()->create();

        $status->markFailed(['message' => 'Connection timeout']);

        expect($status->fresh()->status)->toBe('failed');
        expect($status->fresh()->error['message'])->toBe('Connection timeout');
    });

    it('updates progress', function () {
        $status = JobStatus::factory()->processing()->create();

        $status->updateProgress(50);

        expect($status->fresh()->progress)->toBe(50);
    });
});

describe('WorkflowDispatchService', function () {
    it('dispatches a workflow', function () {
        Queue::fake();

        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'is_active' => true,
            'nodes' => [['id' => 'n1', 'type' => 'trigger']],
        ]);

        $service = app(WorkflowDispatchService::class);
        $result = $service->dispatch($workflow, 'manual', [], $this->user);

        expect($result['execution'])->toBeInstanceOf(Execution::class);
        expect($result['job_id'])->not->toBeNull();

        Queue::assertPushed(ExecuteWorkflowJob::class);
    });

    it('throws error for inactive workflow', function () {
        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'is_active' => false,
        ]);

        $service = app(WorkflowDispatchService::class);

        expect(fn () => $service->dispatch($workflow))
            ->toThrow(RuntimeException::class, 'Workflow is not active.');
    });

    it('throws error for workflow without nodes', function () {
        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'is_active' => true,
            'nodes' => [],
        ]);

        $service = app(WorkflowDispatchService::class);

        expect(fn () => $service->dispatch($workflow))
            ->toThrow(RuntimeException::class, 'Workflow has no nodes.');
    });
});

describe('JobCallbackController', function () {
    it('processes callback from Go engine', function () {
        $execution = Execution::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'running',
        ]);

        $jobStatus = JobStatus::factory()->processing()->create([
            'execution_id' => $execution->id,
        ]);

        $payload = [
            'job_id' => $jobStatus->job_id,
            'callback_token' => $jobStatus->callback_token, // Required token
            'execution_id' => $execution->id,
            'status' => 'completed',
            'nodes' => [
                ['node_id' => 'n1', 'node_type' => 'http_request', 'status' => 'completed', 'output' => ['data' => 'test'], 'sequence' => 1],
            ],
            'duration_ms' => 500,
        ];

        $response = $this->withHeaders(engineHeaders($payload))
            ->postJson('/api/v1/jobs/callback', $payload);

        $response->assertSuccessful();
        expect($execution->fresh()->status->value)->toBe('completed');
        expect($jobStatus->fresh()->status)->toBe('completed');
    });

    it('rejects callback with invalid token', function () {
        $execution = Execution::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'running',
        ]);

        $jobStatus = JobStatus::factory()->processing()->create([
            'execution_id' => $execution->id,
        ]);

        $payload = [
            'job_id' => $jobStatus->job_id,
            'callback_token' => str_repeat('x', 64), // Invalid token
            'execution_id' => $execution->id,
            'status' => 'completed',
        ];

        $response = $this->withHeaders(engineHeaders($payload))
            ->postJson('/api/v1/jobs/callback', $payload);

        $response->assertStatus(401);
        expect($response->json('error'))->toBe('Invalid callback token');
    });

    it('handles failed callback', function () {
        $execution = Execution::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'running',
        ]);

        $jobStatus = JobStatus::factory()->processing()->create([
            'execution_id' => $execution->id,
        ]);

        $payload = [
            'job_id' => $jobStatus->job_id,
            'callback_token' => $jobStatus->callback_token,
            'execution_id' => $execution->id,
            'status' => 'failed',
            'error' => ['message' => 'Node execution failed'],
        ];

        $response = $this->withHeaders(engineHeaders($payload))
            ->postJson('/api/v1/jobs/callback', $payload);

        $response->assertSuccessful();
        expect($execution->fresh()->status->value)->toBe('failed');
        expect($jobStatus->fresh()->status)->toBe('failed');
    });

    it('updates progress', function () {
        $jobStatus = JobStatus::factory()->processing()->create();

        $payload = [
            'job_id' => $jobStatus->job_id,
            'callback_token' => $jobStatus->callback_token,
            'progress' => 75,
        ];

        $response = $this->withHeaders(engineHeaders($payload))
            ->postJson('/api/v1/jobs/progress', $payload);

        $response->assertSuccessful();
        expect($jobStatus->fresh()->progress)->toBe(75);
    });

    it('rejects progress update with invalid token', function () {
        $jobStatus = JobStatus::factory()->processing()->create();

        $payload = [
            'job_id' => $jobStatus->job_id,
            'callback_token' => str_repeat('y', 64),
            'progress' => 50,
        ];

        $response = $this->withHeaders(engineHeaders($payload))
            ->postJson('/api/v1/jobs/progress', $payload);

        $response->assertStatus(401);
    });
});
