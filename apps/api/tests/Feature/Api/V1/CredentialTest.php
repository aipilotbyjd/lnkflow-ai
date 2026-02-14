<?php

use App\Models\Credential;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\CredentialTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(CredentialTypeSeeder::class);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, [
        'role' => 'owner',
        'joined_at' => now(),
    ]);
    Passport::actingAs($this->user);
});

describe('index', function () {
    it('returns credentials for workspace', function () {
        Credential::factory()->count(3)->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/credentials");

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'type',
                        'data',
                        'is_expired',
                        'created_at',
                    ],
                ],
            ]);
    });

    it('filters by type', function () {
        Credential::factory()->apiKey()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);
        Credential::factory()->bearerToken()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/credentials?type=api_key");

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    });

    it('returns forbidden for non-member', function () {
        $otherUser = User::factory()->create();
        Passport::actingAs($otherUser);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/credentials");

        $response->assertForbidden();
    });
});

describe('store', function () {
    it('creates a credential', function () {
        $payload = [
            'name' => 'My API Key',
            'type' => 'api_key',
            'data' => [
                'api_key' => 'sk-test-12345', // pragma: allowlist secret
                'header_name' => 'X-API-Key',
            ],
        ];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/credentials", $payload);

        $response->assertCreated()
            ->assertJsonPath('credential.name', 'My API Key')
            ->assertJsonPath('credential.type', 'api_key');

        $this->assertDatabaseHas('credentials', [
            'workspace_id' => $this->workspace->id,
            'name' => 'My API Key',
            'type' => 'api_key',
        ]);
    });

    it('masks secret fields in response', function () {
        $payload = [
            'name' => 'My API Key',
            'type' => 'api_key',
            'data' => [
                'api_key' => 'sk-test-secret-key-12345', // pragma: allowlist secret
                'header_name' => 'X-API-Key',
            ],
        ];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/credentials", $payload);

        $response->assertCreated();

        $data = $response->json('credential.data');
        expect($data['api_key'])->toContain('••••••••');
        expect($data['header_name'])->toBe('X-API-Key');
    });

    it('validates required fields', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/credentials", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'type', 'data']);
    });

    it('validates unique name in workspace', function () {
        Credential::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'name' => 'Existing Credential',
        ]);

        $payload = [
            'name' => 'Existing Credential',
            'type' => 'api_key',
            'data' => ['api_key' => 'test'], // pragma: allowlist secret
        ];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/credentials", $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    it('validates credential type exists', function () {
        $payload = [
            'name' => 'Invalid Type',
            'type' => 'non_existent_type',
            'data' => ['key' => 'value'],
        ];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/credentials", $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    });
});

describe('show', function () {
    it('returns a single credential', function () {
        $credential = Credential::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$credential->id}");

        $response->assertSuccessful()
            ->assertJsonPath('credential.id', $credential->id);
    });

    it('returns not found for credential in different workspace', function () {
        $otherWorkspace = Workspace::factory()->create();
        $credential = Credential::factory()->create([
            'workspace_id' => $otherWorkspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$credential->id}");

        $response->assertNotFound();
    });
});

describe('update', function () {
    it('updates a credential name', function () {
        $credential = Credential::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'name' => 'Original Name',
        ]);

        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$credential->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('credential.name', 'Updated Name');

        $this->assertDatabaseHas('credentials', [
            'id' => $credential->id,
            'name' => 'Updated Name',
        ]);
    });

    it('updates credential data', function () {
        $credential = Credential::factory()->apiKey()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$credential->id}", [
            'data' => [
                'api_key' => 'new-secret-key', // pragma: allowlist secret
                'header_name' => 'Authorization',
            ],
        ]);

        $response->assertSuccessful();

        $credential->refresh();
        $decryptedData = $credential->getDecryptedData();
        expect($decryptedData['api_key'])->toBe('new-secret-key');
        expect($decryptedData['header_name'])->toBe('Authorization');
    });

    it('validates unique name excluding self', function () {
        $credential1 = Credential::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'name' => 'Credential One',
        ]);
        $credential2 = Credential::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
            'name' => 'Credential Two',
        ]);

        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$credential2->id}", [
            'name' => 'Credential One',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });
});

describe('destroy', function () {
    it('deletes a credential', function () {
        $credential = Credential::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$credential->id}");

        $response->assertSuccessful();

        $this->assertSoftDeleted('credentials', ['id' => $credential->id]);
    });
});

describe('test', function () {
    it('tests a credential with http method', function () {
        Http::fake([
            'https://api.github.com/user' => Http::response(['login' => 'testuser'], 200),
        ]);

        $credential = Credential::factory()->github()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$credential->id}/test");

        $response->assertSuccessful()
            ->assertJsonPath('success', true);

        $credential->refresh();
        expect($credential->last_used_at)->not->toBeNull();
    });

    it('returns error for failed credential test', function () {
        Http::fake([
            'https://api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401),
        ]);

        $credential = Credential::factory()->github()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$credential->id}/test");

        $response->assertUnprocessable()
            ->assertJsonPath('success', false);
    });

    it('returns error for credential type without test config', function () {
        $credential = Credential::factory()->apiKey()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$credential->id}/test");

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This credential type does not support testing.');
    });
});

describe('permissions', function () {
    it('allows viewer to view credentials', function () {
        $viewer = User::factory()->create();
        $this->workspace->members()->attach($viewer->id, [
            'role' => 'viewer',
            'joined_at' => now(),
        ]);
        Passport::actingAs($viewer);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/credentials");

        $response->assertSuccessful();
    });

    it('forbids viewer from creating credentials', function () {
        $viewer = User::factory()->create();
        $this->workspace->members()->attach($viewer->id, [
            'role' => 'viewer',
            'joined_at' => now(),
        ]);
        Passport::actingAs($viewer);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/credentials", [
            'name' => 'Test',
            'type' => 'api_key',
            'data' => ['api_key' => 'test'],
        ]);

        $response->assertForbidden();
    });
});

describe('expiration', function () {
    it('shows expired status for expired credential', function () {
        $credential = Credential::factory()->expired()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$credential->id}");

        $response->assertSuccessful()
            ->assertJsonPath('credential.is_expired', true);
    });

    it('shows not expired for valid credential', function () {
        $credential = Credential::factory()->expiresIn(30)->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/credentials/{$credential->id}");

        $response->assertSuccessful()
            ->assertJsonPath('credential.is_expired', false);
    });
});
