<?php

use App\Models\User;
use App\Models\Webhook;
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
    $this->workflow = Workflow::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
    ]);
    Passport::actingAs($this->user);
});

describe('index', function () {
    it('returns webhooks for workspace', function () {
        Webhook::factory()->count(3)->create([
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/webhooks");

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'workflow_id',
                        'uuid',
                        'url',
                        'methods',
                        'is_active',
                    ],
                ],
            ]);
    });

    it('filters by is_active', function () {
        Webhook::factory()->create(['workspace_id' => $this->workspace->id, 'is_active' => true]);
        Webhook::factory()->inactive()->create(['workspace_id' => $this->workspace->id]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/webhooks?is_active=1");

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    });
});

describe('store', function () {
    it('creates a webhook', function () {
        $payload = [
            'workflow_id' => $this->workflow->id,
            'methods' => ['POST', 'PUT'],
        ];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/webhooks", $payload);

        $response->assertCreated()
            ->assertJsonPath('webhook.workflow_id', $this->workflow->id)
            ->assertJsonPath('webhook.methods', ['POST', 'PUT']);

        $this->assertDatabaseHas('webhooks', [
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);
    });

    it('creates webhook with custom path', function () {
        $payload = [
            'workflow_id' => $this->workflow->id,
            'path' => 'my-custom-path',
        ];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/webhooks", $payload);

        $response->assertCreated()
            ->assertJsonPath('webhook.path', 'my-custom-path');

        expect($response->json('webhook.url'))->toContain('my-custom-path');
    });

    it('creates webhook with header auth', function () {
        $payload = [
            'workflow_id' => $this->workflow->id,
            'auth_type' => 'header',
            'auth_config' => [
                'header_name' => 'X-Secret',
                'header_value' => 'my-secret-value',
            ],
        ];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/webhooks", $payload);

        $response->assertCreated()
            ->assertJsonPath('webhook.auth_type', 'header');
    });

    it('validates unique workflow_id', function () {
        Webhook::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/webhooks", [
            'workflow_id' => $this->workflow->id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['workflow_id']);
    });

    it('validates workflow belongs to workspace', function () {
        $otherWorkspace = Workspace::factory()->create();
        $otherWorkflow = Workflow::factory()->create([
            'workspace_id' => $otherWorkspace->id,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/webhooks", [
            'workflow_id' => $otherWorkflow->id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['workflow_id']);
    });
});

describe('show', function () {
    it('returns a single webhook', function () {
        $webhook = Webhook::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/webhooks/{$webhook->id}");

        $response->assertSuccessful()
            ->assertJsonPath('webhook.id', $webhook->id);
    });
});

describe('update', function () {
    it('updates webhook path', function () {
        $webhook = Webhook::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/webhooks/{$webhook->id}", [
            'path' => 'new-path',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('webhook.path', 'new-path');
    });

    it('updates webhook methods', function () {
        $webhook = Webhook::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
            'methods' => ['POST'],
        ]);

        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/webhooks/{$webhook->id}", [
            'methods' => ['GET', 'POST', 'PUT'],
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('webhook.methods', ['GET', 'POST', 'PUT']);
    });
});

describe('destroy', function () {
    it('deletes a webhook', function () {
        $webhook = Webhook::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/webhooks/{$webhook->id}");

        $response->assertSuccessful();

        $this->assertDatabaseMissing('webhooks', ['id' => $webhook->id]);
    });
});

describe('regenerateUuid', function () {
    it('regenerates webhook uuid', function () {
        $webhook = Webhook::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $originalUuid = $webhook->uuid;

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/webhooks/{$webhook->id}/regenerate-uuid");

        $response->assertSuccessful();

        $webhook->refresh();
        expect($webhook->uuid)->not->toBe($originalUuid);
    });
});

describe('activate/deactivate', function () {
    it('activates a webhook', function () {
        $webhook = Webhook::factory()->inactive()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/webhooks/{$webhook->id}/activate");

        $response->assertSuccessful()
            ->assertJsonPath('webhook.is_active', true);
    });

    it('deactivates a webhook', function () {
        $webhook = Webhook::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/webhooks/{$webhook->id}/deactivate");

        $response->assertSuccessful()
            ->assertJsonPath('webhook.is_active', false);
    });
});

describe('forWorkflow', function () {
    it('returns webhook for workflow', function () {
        $webhook = Webhook::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}/webhook");

        $response->assertSuccessful()
            ->assertJsonPath('webhook.id', $webhook->id);
    });

    it('returns null for workflow without webhook', function () {
        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}/webhook");

        $response->assertSuccessful()
            ->assertJsonPath('webhook', null);
    });
});
