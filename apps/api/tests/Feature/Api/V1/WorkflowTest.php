<?php

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

describe('index', function () {
    it('returns paginated workflows for workspace', function () {
        Workflow::factory()->count(3)->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/workflows");

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'description',
                        'is_active',
                        'trigger_type',
                        'nodes',
                        'edges',
                    ],
                ],
            ]);
    });

    it('returns forbidden for non-member', function () {
        $otherUser = User::factory()->create();
        Passport::actingAs($otherUser);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/workflows");

        $response->assertForbidden();
    });
});

describe('store', function () {
    it('creates a workflow', function () {
        $payload = [
            'name' => 'My Test Workflow',
            'description' => 'A test workflow',
            'trigger_type' => 'manual',
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'trigger_manual',
                    'position' => ['x' => 100, 'y' => 100],
                    'data' => ['label' => 'Manual Trigger'],
                ],
            ],
            'edges' => [],
        ];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows", $payload);

        $response->assertCreated()
            ->assertJsonPath('workflow.name', 'My Test Workflow')
            ->assertJsonPath('workflow.trigger_type', 'manual');

        $this->assertDatabaseHas('workflows', [
            'workspace_id' => $this->workspace->id,
            'name' => 'My Test Workflow',
        ]);
    });

    it('validates required fields', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'trigger_type', 'nodes']);
    });

    it('validates webhook path for webhook trigger', function () {
        $payload = [
            'name' => 'Webhook Workflow',
            'trigger_type' => 'webhook',
            'trigger_config' => [],
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'trigger_webhook',
                    'position' => ['x' => 100, 'y' => 100],
                    'data' => ['label' => 'Webhook'],
                ],
            ],
            'edges' => [],
        ];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows", $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.path']);
    });
});

describe('show', function () {
    it('returns a single workflow', function () {
        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$workflow->id}");

        $response->assertSuccessful()
            ->assertJsonPath('workflow.id', $workflow->id);
    });

    it('returns not found for workflow in different workspace', function () {
        $otherWorkspace = Workspace::factory()->create();
        $workflow = Workflow::factory()->create([
            'workspace_id' => $otherWorkspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$workflow->id}");

        $response->assertNotFound();
    });
});

describe('update', function () {
    it('updates a workflow', function () {
        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'name' => 'Original Name',
        ]);

        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$workflow->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('workflow.name', 'Updated Name');

        $this->assertDatabaseHas('workflows', [
            'id' => $workflow->id,
            'name' => 'Updated Name',
        ]);
    });

    it('returns locked status when workflow is locked', function () {
        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'is_locked' => true,
        ]);

        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$workflow->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(423);
    });
});

describe('destroy', function () {
    it('deletes a workflow', function () {
        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$workflow->id}");

        $response->assertSuccessful();

        $this->assertSoftDeleted('workflows', ['id' => $workflow->id]);
    });
});

describe('activate', function () {
    it('activates a workflow', function () {
        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'is_active' => false,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$workflow->id}/activate");

        $response->assertSuccessful()
            ->assertJsonPath('workflow.is_active', true);

        $this->assertDatabaseHas('workflows', [
            'id' => $workflow->id,
            'is_active' => true,
        ]);
    });
});

describe('deactivate', function () {
    it('deactivates a workflow', function () {
        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$workflow->id}/deactivate");

        $response->assertSuccessful()
            ->assertJsonPath('workflow.is_active', false);
    });
});

describe('duplicate', function () {
    it('duplicates a workflow', function () {
        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'name' => 'Original Workflow',
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$workflow->id}/duplicate");

        $response->assertCreated()
            ->assertJsonPath('workflow.name', 'Original Workflow (Copy)')
            ->assertJsonPath('workflow.is_active', false);

        $this->assertDatabaseCount('workflows', 2);
    });
});

describe('permissions', function () {
    it('allows viewer to view workflows', function () {
        $viewer = User::factory()->create();
        $this->workspace->members()->attach($viewer->id, [
            'role' => 'viewer',
            'joined_at' => now(),
        ]);
        Passport::actingAs($viewer);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/workflows");

        $response->assertSuccessful();
    });

    it('forbids viewer from creating workflows', function () {
        $viewer = User::factory()->create();
        $this->workspace->members()->attach($viewer->id, [
            'role' => 'viewer',
            'joined_at' => now(),
        ]);
        Passport::actingAs($viewer);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows", [
            'name' => 'Test',
            'trigger_type' => 'manual',
            'nodes' => [['id' => '1', 'type' => 'test', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Test']]],
            'edges' => [],
        ]);

        $response->assertForbidden();
    });
});
