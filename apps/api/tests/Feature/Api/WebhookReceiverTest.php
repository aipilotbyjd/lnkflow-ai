<?php

use App\Enums\ExecutionMode;
use App\Enums\ExecutionStatus;
use App\Enums\WebhookAuthType;
use App\Jobs\ExecuteWorkflowJob;
use App\Models\Execution;
use App\Models\Webhook;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->workspace = Workspace::factory()->create();
    $this->workflow = Workflow::factory()->create([
        'workspace_id' => $this->workspace->id,
        'is_active' => true,
        'nodes' => [
            ['id' => 't1', 'type' => 'trigger_webhook', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Webhook']],
        ],
        'edges' => [],
    ]);
});

describe('handle', function () {
    it('receives webhook and creates execution', function () {
        $webhook = Webhook::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->postJson("/api/webhooks/{$webhook->uuid}", [
            'event' => 'test',
            'data' => ['key' => 'value'],
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('executions', [
            'workflow_id' => $this->workflow->id,
            'mode' => ExecutionMode::Webhook->value,
            'status' => ExecutionStatus::Pending->value,
        ]);

        Queue::assertPushed(ExecuteWorkflowJob::class);

        $webhook->refresh();
        expect($webhook->call_count)->toBe(1);
        expect($webhook->last_called_at)->not->toBeNull();
    });

    it('receives webhook with custom path', function () {
        $webhook = Webhook::factory()->withPath('my-path')->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->postJson("/api/webhooks/{$webhook->uuid}/my-path", [
            'event' => 'test',
        ]);

        $response->assertSuccessful();
    });

    it('returns 404 for invalid uuid', function () {
        $response = $this->postJson('/api/webhooks/invalid-uuid', []);

        $response->assertNotFound();
    });

    it('returns 404 for inactive webhook', function () {
        $webhook = Webhook::factory()->inactive()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->postJson("/api/webhooks/{$webhook->uuid}", []);

        $response->assertNotFound();
    });

    it('returns 404 for wrong custom path', function () {
        $webhook = Webhook::factory()->withPath('correct-path')->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->postJson("/api/webhooks/{$webhook->uuid}/wrong-path", []);

        $response->assertNotFound();
    });

    it('returns 405 for method not allowed', function () {
        $webhook = Webhook::factory()->withMethods(['POST'])->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->getJson("/api/webhooks/{$webhook->uuid}");

        $response->assertStatus(405);
    });

    it('allows configured methods', function () {
        $webhook = Webhook::factory()->withMethods(['GET', 'POST'])->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->getJson("/api/webhooks/{$webhook->uuid}");

        $response->assertSuccessful();
    });
});

describe('authentication', function () {
    it('validates header auth', function () {
        $webhook = Webhook::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
            'auth_type' => WebhookAuthType::Header,
            'auth_config' => [
                'header_name' => 'X-Secret',
                'header_value' => 'my-secret',
            ],
        ]);

        $response = $this->postJson("/api/webhooks/{$webhook->uuid}", [], [
            'X-Secret' => 'wrong-secret',
        ]);

        $response->assertUnauthorized();

        $response = $this->postJson("/api/webhooks/{$webhook->uuid}", [], [
            'X-Secret' => 'my-secret',
        ]);

        $response->assertSuccessful();
    });

    it('validates bearer auth', function () {
        $webhook = Webhook::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
            'auth_type' => WebhookAuthType::Bearer,
            'auth_config' => [
                'token' => 'my-bearer-token',
            ],
        ]);

        $response = $this->postJson("/api/webhooks/{$webhook->uuid}", [], [
            'Authorization' => 'Bearer wrong-token',
        ]);

        $response->assertUnauthorized();

        $response = $this->postJson("/api/webhooks/{$webhook->uuid}", [], [
            'Authorization' => 'Bearer my-bearer-token',
        ]);

        $response->assertSuccessful();
    });

    it('allows no auth when configured', function () {
        $webhook = Webhook::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
            'auth_type' => WebhookAuthType::None,
        ]);

        $response = $this->postJson("/api/webhooks/{$webhook->uuid}", []);

        $response->assertSuccessful();
    });
});

describe('response', function () {
    it('returns custom response status', function () {
        $webhook = Webhook::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
            'response_status' => 202,
        ]);

        $response = $this->postJson("/api/webhooks/{$webhook->uuid}", []);

        $response->assertStatus(202);
    });

    it('returns custom response body', function () {
        $webhook = Webhook::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
            'response_body' => ['message' => 'Custom response'],
        ]);

        $response = $this->postJson("/api/webhooks/{$webhook->uuid}", []);

        $response->assertSuccessful()
            ->assertJsonPath('message', 'Custom response');
    });
});

describe('trigger data', function () {
    it('captures request data in execution', function () {
        $webhook = Webhook::factory()->create([
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $this->postJson("/api/webhooks/{$webhook->uuid}?query_param=value", [
            'body_key' => 'body_value',
        ]);

        $execution = Execution::query()
            ->where('workflow_id', $this->workflow->id)
            ->first();

        expect($execution->trigger_data['body']['body_key'])->toBe('body_value');
        expect($execution->trigger_data['query']['query_param'])->toBe('value');
        expect($execution->trigger_data['method'])->toBe('POST');
    });
});
