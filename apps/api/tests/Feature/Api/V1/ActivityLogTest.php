<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\ActivityLogService;
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

describe('index', function () {
    it('returns paginated activity logs', function () {
        ActivityLog::factory()->count(5)->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/activity");

        $response->assertSuccessful()
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'action',
                        'description',
                        'user',
                        'subject_type',
                        'subject_id',
                        'created_at',
                    ],
                ],
                'meta',
            ]);
    });

    it('returns logs ordered by created_at descending', function () {
        ActivityLog::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'action' => 'first.action',
            'created_at' => now()->subDay(),
        ]);
        ActivityLog::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'action' => 'latest.action',
            'created_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/activity");

        $response->assertSuccessful();
        expect($response->json('data.0.action'))->toBe('latest.action');
    });

    it('filters by action prefix', function () {
        ActivityLog::factory()->create([
            'workspace_id' => $this->workspace->id,
            'action' => 'workflow.created',
        ]);
        ActivityLog::factory()->create([
            'workspace_id' => $this->workspace->id,
            'action' => 'credential.created',
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/activity?action=workflow");

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    });

    it('filters by user_id', function () {
        $otherUser = User::factory()->create();

        ActivityLog::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
        ]);
        ActivityLog::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $otherUser->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/activity?user_id={$this->user->id}");

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    });

    it('returns forbidden for non-member', function () {
        $otherUser = User::factory()->create();
        Passport::actingAs($otherUser);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/activity");

        $response->assertForbidden();
    });
});

describe('ActivityLogService', function () {
    it('logs an action', function () {
        $service = app(ActivityLogService::class);

        $log = $service->log(
            workspace: $this->workspace,
            action: 'test.action',
            user: $this->user,
            description: 'Test description'
        );

        expect($log)->toBeInstanceOf(ActivityLog::class);
        expect($log->action)->toBe('test.action');
        expect($log->description)->toBe('Test description');
    });

    it('logs a created event', function () {
        $service = app(ActivityLogService::class);
        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $log = $service->logCreated(
            workspace: $this->workspace,
            subject: $workflow,
            user: $this->user
        );

        expect($log->action)->toBe('workflow.created');
        expect($log->subject_type)->toBe(Workflow::class);
        expect($log->subject_id)->toBe($workflow->id);
        expect($log->new_values)->not->toBeNull();
    });

    it('logs an updated event', function () {
        $service = app(ActivityLogService::class);
        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'name' => 'Original Name',
        ]);

        $oldValues = $workflow->toArray();
        $workflow->update(['name' => 'Updated Name']);

        $log = $service->logUpdated(
            workspace: $this->workspace,
            subject: $workflow,
            oldValues: $oldValues,
            user: $this->user
        );

        expect($log->action)->toBe('workflow.updated');
        expect($log->old_values['name'])->toBe('Original Name');
        expect($log->new_values['name'])->toBe('Updated Name');
    });

    it('logs a deleted event', function () {
        $service = app(ActivityLogService::class);
        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $log = $service->logDeleted(
            workspace: $this->workspace,
            subject: $workflow,
            user: $this->user
        );

        expect($log->action)->toBe('workflow.deleted');
        expect($log->old_values)->not->toBeNull();
    });
});
