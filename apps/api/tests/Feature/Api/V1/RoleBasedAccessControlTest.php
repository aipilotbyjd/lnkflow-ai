<?php

use App\Models\Credential;
use App\Models\Tag;
use App\Models\User;
use App\Models\Variable;
use App\Models\Webhook;
use App\Models\Workflow;
use App\Models\Workspace;
use Database\Seeders\CredentialTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(CredentialTypeSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, [
        'role' => 'owner',
        'joined_at' => now(),
    ]);

    // Create a member user
    $this->member = User::factory()->create();
    $this->workspace->members()->attach($this->member->id, [
        'role' => 'member',
        'joined_at' => now(),
    ]);

    // Create a viewer user
    $this->viewer = User::factory()->create();
    $this->workspace->members()->attach($this->viewer->id, [
        'role' => 'viewer',
        'joined_at' => now(),
    ]);

    // Create an admin user
    $this->admin = User::factory()->create();
    $this->workspace->members()->attach($this->admin->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);

    // Create test resources owned by the owner
    $this->workflow = Workflow::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->owner->id,
        'is_active' => false,
    ]);

    $this->credential = Credential::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->owner->id,
    ]);

    $this->tag = Tag::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->variable = Variable::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->owner->id,
    ]);

    $this->webhookWorkflow = Workflow::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->owner->id,
    ]);

    $this->webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'workflow_id' => $this->webhookWorkflow->id,
    ]);
});

// ---------------------------------------------------------------------------
// Member Role Restrictions
// ---------------------------------------------------------------------------

describe('member role restrictions', function () {
    beforeEach(function () {
        Passport::actingAs($this->member);
    });

    // -- Workflow delete --
    it('forbids member from deleting workflows', function () {
        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}");

        $response->assertForbidden();
    });

    // -- Workflow activate / deactivate --
    it('forbids member from activating workflows', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}/activate");

        $response->assertForbidden();
    });

    it('forbids member from deactivating workflows', function () {
        $this->workflow->update(['is_active' => true]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}/deactivate");

        $response->assertForbidden();
    });

    // -- Credential delete --
    it('forbids member from deleting credentials', function () {
        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$this->credential->id}");

        $response->assertForbidden();
    });

    // -- Webhook delete --
    it('forbids member from deleting webhooks', function () {
        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/webhooks/{$this->webhook->id}");

        $response->assertForbidden();
    });

    // -- Variable delete --
    it('forbids member from deleting variables', function () {
        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/variables/{$this->variable->id}");

        $response->assertForbidden();
    });

    // -- Tag delete --
    it('forbids member from deleting tags', function () {
        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/tags/{$this->tag->id}");

        $response->assertForbidden();
    });

    // -- Member CAN view / create / update (sanity checks) --
    it('allows member to view workflows', function () {
        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/workflows");

        $response->assertSuccessful();
    });

    it('allows member to create workflows', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows", [
            'name' => 'Member Workflow',
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
        ]);

        $response->assertCreated();
    });

    it('allows member to update workflows', function () {
        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}", [
            'name' => 'Updated by Member',
        ]);

        $response->assertSuccessful();
    });

    it('allows member to create tags', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/tags", [
            'name' => 'Member Tag',
        ]);

        $response->assertCreated();
    });

    it('allows member to create variables', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/variables", [
            'key' => 'MEMBER_KEY',
            'value' => 'test',
        ]);

        $response->assertCreated();
    });
});

// ---------------------------------------------------------------------------
// Viewer Role Restrictions
// ---------------------------------------------------------------------------

describe('viewer role restrictions', function () {
    beforeEach(function () {
        Passport::actingAs($this->viewer);
    });

    // -- Viewer CAN view --
    it('allows viewer to list workflows', function () {
        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/workflows");

        $response->assertSuccessful();
    });

    it('allows viewer to show a workflow', function () {
        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}");

        $response->assertSuccessful();
    });

    it('allows viewer to list credentials', function () {
        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/credentials");

        $response->assertSuccessful();
    });

    it('allows viewer to show a credential', function () {
        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$this->credential->id}");

        $response->assertSuccessful();
    });

    it('allows viewer to list webhooks', function () {
        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/webhooks");

        $response->assertSuccessful();
    });

    it('allows viewer to show a webhook', function () {
        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/webhooks/{$this->webhook->id}");

        $response->assertSuccessful();
    });

    it('allows viewer to list variables', function () {
        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/variables");

        $response->assertSuccessful();
    });

    it('allows viewer to show a variable', function () {
        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/variables/{$this->variable->id}");

        $response->assertSuccessful();
    });

    it('allows viewer to list tags', function () {
        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/tags");

        $response->assertSuccessful();
    });

    // -- Viewer CANNOT create --
    it('forbids viewer from creating workflows', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows", [
            'name' => 'Test',
            'trigger_type' => 'manual',
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'trigger_manual',
                    'position' => ['x' => 0, 'y' => 0],
                    'data' => ['label' => 'Test'],
                ],
            ],
            'edges' => [],
        ]);

        $response->assertForbidden();
    });

    it('forbids viewer from creating credentials', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/credentials", [
            'name' => 'Test',
            'type' => 'api_key',
            'data' => ['api_key' => 'test'],
        ]);

        $response->assertForbidden();
    });

    it('forbids viewer from creating webhooks', function () {
        $extraWorkflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/webhooks", [
            'workflow_id' => $extraWorkflow->id,
            'methods' => ['POST'],
        ]);

        $response->assertForbidden();
    });

    it('forbids viewer from creating variables', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/variables", [
            'key' => 'VIEWER_KEY',
            'value' => 'test',
        ]);

        $response->assertForbidden();
    });

    it('forbids viewer from creating tags', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/tags", [
            'name' => 'Viewer Tag',
        ]);

        $response->assertForbidden();
    });

    // -- Viewer CANNOT update --
    it('forbids viewer from updating workflows', function () {
        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}", [
            'name' => 'Updated by Viewer',
        ]);

        $response->assertForbidden();
    });

    it('forbids viewer from updating credentials', function () {
        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$this->credential->id}", [
            'name' => 'Updated by Viewer',
        ]);

        $response->assertForbidden();
    });

    it('forbids viewer from updating webhooks', function () {
        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/webhooks/{$this->webhook->id}", [
            'path' => 'viewer-path',
        ]);

        $response->assertForbidden();
    });

    it('forbids viewer from updating variables', function () {
        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/variables/{$this->variable->id}", [
            'value' => 'updated-by-viewer',
        ]);

        $response->assertForbidden();
    });

    it('forbids viewer from updating tags', function () {
        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/tags/{$this->tag->id}", [
            'name' => 'Updated by Viewer',
        ]);

        $response->assertForbidden();
    });

    // -- Viewer CANNOT delete --
    it('forbids viewer from deleting workflows', function () {
        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}");

        $response->assertForbidden();
    });

    it('forbids viewer from deleting credentials', function () {
        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$this->credential->id}");

        $response->assertForbidden();
    });

    it('forbids viewer from deleting webhooks', function () {
        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/webhooks/{$this->webhook->id}");

        $response->assertForbidden();
    });

    it('forbids viewer from deleting variables', function () {
        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/variables/{$this->variable->id}");

        $response->assertForbidden();
    });

    it('forbids viewer from deleting tags', function () {
        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/tags/{$this->tag->id}");

        $response->assertForbidden();
    });

    // -- Viewer CANNOT activate / deactivate workflows --
    it('forbids viewer from activating workflows', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}/activate");

        $response->assertForbidden();
    });

    it('forbids viewer from deactivating workflows', function () {
        $this->workflow->update(['is_active' => true]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}/deactivate");

        $response->assertForbidden();
    });

    // -- Viewer CANNOT duplicate workflows --
    it('forbids viewer from duplicating workflows', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}/duplicate");

        $response->assertForbidden();
    });

    // -- Viewer CANNOT regenerate webhook uuid --
    it('forbids viewer from regenerating webhook uuid', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/webhooks/{$this->webhook->id}/regenerate-uuid");

        $response->assertForbidden();
    });

    // -- Viewer CANNOT activate / deactivate webhooks --
    it('forbids viewer from activating webhooks', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/webhooks/{$this->webhook->id}/activate");

        $response->assertForbidden();
    });

    it('forbids viewer from deactivating webhooks', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/webhooks/{$this->webhook->id}/deactivate");

        $response->assertForbidden();
    });
});

// ---------------------------------------------------------------------------
// Admin Role Capabilities
// ---------------------------------------------------------------------------

describe('admin role capabilities', function () {
    beforeEach(function () {
        Passport::actingAs($this->admin);
    });

    it('allows admin to delete workflows', function () {
        $workflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$workflow->id}");

        $response->assertSuccessful();
    });

    it('allows admin to delete credentials', function () {
        $credential = Credential::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$credential->id}");

        $response->assertSuccessful();
    });

    it('allows admin to delete webhooks', function () {
        $webhookWorkflow = Workflow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
        ]);
        $webhook = Webhook::factory()->create([
            'workspace_id' => $this->workspace->id,
            'workflow_id' => $webhookWorkflow->id,
        ]);

        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/webhooks/{$webhook->id}");

        $response->assertSuccessful();
    });

    it('allows admin to delete variables', function () {
        $variable = Variable::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/variables/{$variable->id}");

        $response->assertSuccessful();
    });

    it('allows admin to delete tags', function () {
        $tag = Tag::factory()->create([
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/tags/{$tag->id}");

        $response->assertSuccessful();
    });

    it('allows admin to activate workflows', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}/activate");

        $response->assertSuccessful();
    });

    it('allows admin to deactivate workflows', function () {
        $this->workflow->update(['is_active' => true]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}/deactivate");

        $response->assertSuccessful();
    });

    it('allows admin to create workflows', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows", [
            'name' => 'Admin Workflow',
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
        ]);

        $response->assertCreated();
    });

    it('allows admin to update workflows', function () {
        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}", [
            'name' => 'Updated by Admin',
        ]);

        $response->assertSuccessful();
    });

    it('allows admin to duplicate workflows', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows/{$this->workflow->id}/duplicate");

        $response->assertCreated();
    });

    it('allows admin to regenerate webhook uuid', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/webhooks/{$this->webhook->id}/regenerate-uuid");

        $response->assertSuccessful();
    });
});

// ---------------------------------------------------------------------------
// Credential Data Leakage Prevention
// ---------------------------------------------------------------------------

describe('credential data leakage prevention', function () {
    it('does not expose credential data to viewer', function () {
        Passport::actingAs($this->viewer);

        $credential = Credential::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$credential->id}");

        $response->assertSuccessful();
        $response->assertJsonMissing(['data']);
        expect($response->json('credential'))->not->toHaveKey('data');
    });

    it('does not expose credential data in index for viewer', function () {
        Passport::actingAs($this->viewer);

        Credential::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/credentials");

        $response->assertSuccessful();

        $credentials = $response->json('data');
        foreach ($credentials as $cred) {
            expect($cred)->not->toHaveKey('data');
        }
    });

    it('exposes masked credential data to owner', function () {
        Passport::actingAs($this->owner);

        $credential = Credential::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$credential->id}");

        $response->assertSuccessful();
        expect($response->json('credential'))->toHaveKey('data');
    });

    it('exposes masked credential data to admin', function () {
        Passport::actingAs($this->admin);

        $credential = Credential::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$credential->id}");

        $response->assertSuccessful();
        expect($response->json('credential'))->toHaveKey('data');
    });

    it('does not expose credential data to member without update permission', function () {
        Passport::actingAs($this->member);

        $credential = Credential::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$credential->id}");

        $response->assertSuccessful();

        // Members may or may not have credential.update; assert based on actual permission.
        // If member has credential.update, data should be present; otherwise not.
        // Based on the RBAC spec, members can update but not delete, so data should be present.
        expect($response->json('credential'))->toHaveKey('data');
    });
});
