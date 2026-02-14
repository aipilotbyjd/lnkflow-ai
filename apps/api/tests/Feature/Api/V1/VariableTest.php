<?php

use App\Models\User;
use App\Models\Variable;
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
    it('returns variables for workspace', function () {
        Variable::factory()->count(3)->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/variables");

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'key',
                        'value',
                        'description',
                        'is_secret',
                        'created_at',
                    ],
                ],
            ]);
    });

    it('filters by is_secret', function () {
        Variable::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'is_secret' => false,
        ]);
        Variable::factory()->secret()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/variables?is_secret=true");

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    });

    it('returns forbidden for non-member', function () {
        $otherUser = User::factory()->create();
        Passport::actingAs($otherUser);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/variables");

        $response->assertForbidden();
    });
});

describe('store', function () {
    it('creates a variable', function () {
        $payload = [
            'key' => 'API_URL',
            'value' => 'https://api.example.com',
            'description' => 'The API endpoint URL',
        ];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/variables", $payload);

        $response->assertCreated()
            ->assertJsonPath('variable.key', 'API_URL')
            ->assertJsonPath('variable.value', 'https://api.example.com');

        $this->assertDatabaseHas('variables', [
            'workspace_id' => $this->workspace->id,
            'key' => 'API_URL',
        ]);
    });

    it('creates a secret variable with masked value', function () {
        $payload = [
            'key' => 'SECRET_KEY',
            'value' => 'super-secret-value-12345',
            'is_secret' => true,
        ];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/variables", $payload);

        $response->assertCreated()
            ->assertJsonPath('variable.is_secret', true);

        // Value should be masked in response
        $maskedValue = $response->json('variable.value');
        expect($maskedValue)->toContain('••••••••');
    });

    it('validates required fields', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/variables", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['key', 'value']);
    });

    it('validates key format', function () {
        $payload = [
            'key' => 'invalid-key', // Should be uppercase with underscores
            'value' => 'test',
        ];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/variables", $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['key']);
    });

    it('validates unique key in workspace', function () {
        Variable::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'key' => 'EXISTING_KEY',
        ]);

        $payload = [
            'key' => 'EXISTING_KEY',
            'value' => 'test',
        ];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/variables", $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['key']);
    });

    it('allows same key in different workspace', function () {
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $otherWorkspace->members()->attach($this->user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        Variable::factory()->create([
            'workspace_id' => $otherWorkspace->id,
            'created_by' => $this->user->id,
            'key' => 'SHARED_KEY',
        ]);

        $payload = [
            'key' => 'SHARED_KEY',
            'value' => 'test',
        ];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/variables", $payload);

        $response->assertCreated();
    });
});

describe('show', function () {
    it('returns a single variable', function () {
        $variable = Variable::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/variables/{$variable->id}");

        $response->assertSuccessful()
            ->assertJsonPath('variable.id', $variable->id);
    });

    it('returns not found for variable in different workspace', function () {
        $otherWorkspace = Workspace::factory()->create();
        $variable = Variable::factory()->create([
            'workspace_id' => $otherWorkspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/variables/{$variable->id}");

        $response->assertNotFound();
    });
});

describe('update', function () {
    it('updates a variable key', function () {
        $variable = Variable::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'key' => 'ORIGINAL_KEY',
        ]);

        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/variables/{$variable->id}", [
            'key' => 'UPDATED_KEY',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('variable.key', 'UPDATED_KEY');

        $this->assertDatabaseHas('variables', [
            'id' => $variable->id,
            'key' => 'UPDATED_KEY',
        ]);
    });

    it('updates a variable value', function () {
        $variable = Variable::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'value' => 'original-value',
        ]);

        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/variables/{$variable->id}", [
            'value' => 'new-value',
        ]);

        $response->assertSuccessful();

        $variable->refresh();
        expect($variable->getDecryptedValue())->toBe('new-value');
    });

    it('validates unique key excluding self', function () {
        $variable1 = Variable::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'key' => 'KEY_ONE',
        ]);
        $variable2 = Variable::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'key' => 'KEY_TWO',
        ]);

        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/variables/{$variable2->id}", [
            'key' => 'KEY_ONE',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['key']);
    });

    it('allows updating to same key', function () {
        $variable = Variable::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'key' => 'SAME_KEY',
        ]);

        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/variables/{$variable->id}", [
            'key' => 'SAME_KEY',
            'value' => 'updated-value',
        ]);

        $response->assertSuccessful();
    });
});

describe('destroy', function () {
    it('deletes a variable', function () {
        $variable = Variable::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/variables/{$variable->id}");

        $response->assertSuccessful();

        $this->assertDatabaseMissing('variables', ['id' => $variable->id]);
    });
});

describe('permissions', function () {
    it('allows viewer to view variables', function () {
        $viewer = User::factory()->create();
        $this->workspace->members()->attach($viewer->id, [
            'role' => 'viewer',
            'joined_at' => now(),
        ]);
        Passport::actingAs($viewer);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/variables");

        $response->assertSuccessful();
    });

    it('forbids viewer from creating variables', function () {
        $viewer = User::factory()->create();
        $this->workspace->members()->attach($viewer->id, [
            'role' => 'viewer',
            'joined_at' => now(),
        ]);
        Passport::actingAs($viewer);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/variables", [
            'key' => 'TEST_KEY',
            'value' => 'test',
        ]);

        $response->assertForbidden();
    });

    it('allows member to create variables', function () {
        $member = User::factory()->create();
        $this->workspace->members()->attach($member->id, [
            'role' => 'member',
            'joined_at' => now(),
        ]);
        Passport::actingAs($member);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/variables", [
            'key' => 'MEMBER_KEY',
            'value' => 'test',
        ]);

        $response->assertCreated();
    });
});

describe('secret masking', function () {
    it('masks secret values in index', function () {
        Variable::factory()->secret()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'value' => 'super-secret-12345',
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/variables");

        $data = $response->json('data.0');
        expect($data['value'])->toContain('••••••••');
        expect($data['value'])->not->toContain('super-secret');
    });

    it('shows plain value for non-secret variables', function () {
        Variable::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'key' => 'PLAIN_VAR',
            'value' => 'plain-text-value',
            'is_secret' => false,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/variables");

        $data = $response->json('data.0');
        expect($data['value'])->toBe('plain-text-value');
    });
});
