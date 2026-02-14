<?php

use App\Models\Tag;
use App\Models\User;
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
    it('returns tags for workspace', function () {
        Tag::factory()->count(3)->create([
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/tags");

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'color',
                        'created_at',
                    ],
                ],
            ]);
    });

    it('returns tags ordered by name', function () {
        Tag::factory()->withName('Zebra')->create(['workspace_id' => $this->workspace->id]);
        Tag::factory()->withName('Alpha')->create(['workspace_id' => $this->workspace->id]);
        Tag::factory()->withName('Beta')->create(['workspace_id' => $this->workspace->id]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/tags");

        $response->assertSuccessful();
        $names = collect($response->json('data'))->pluck('name')->toArray();
        expect($names)->toBe(['Alpha', 'Beta', 'Zebra']);
    });

    it('returns forbidden for non-member', function () {
        $otherUser = User::factory()->create();
        Passport::actingAs($otherUser);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/tags");

        $response->assertForbidden();
    });
});

describe('store', function () {
    it('creates a tag', function () {
        $payload = [
            'name' => 'Production',
            'color' => '#10b981',
        ];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/tags", $payload);

        $response->assertCreated()
            ->assertJsonPath('tag.name', 'Production')
            ->assertJsonPath('tag.color', '#10b981');

        $this->assertDatabaseHas('tags', [
            'workspace_id' => $this->workspace->id,
            'name' => 'Production',
        ]);
    });

    it('creates a tag with default color', function () {
        $payload = ['name' => 'Development'];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/tags", $payload);

        $response->assertCreated()
            ->assertJsonPath('tag.color', '#6366f1');
    });

    it('validates required name', function () {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/tags", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    it('validates unique name in workspace', function () {
        Tag::factory()->withName('Existing')->create([
            'workspace_id' => $this->workspace->id,
        ]);

        $payload = ['name' => 'Existing'];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/tags", $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    it('validates color hex format', function () {
        $payload = [
            'name' => 'Test',
            'color' => 'invalid-color',
        ];

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/tags", $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['color']);
    });

    it('allows same name in different workspace', function () {
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $otherWorkspace->members()->attach($this->user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        Tag::factory()->withName('Shared')->create([
            'workspace_id' => $otherWorkspace->id,
        ]);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/tags", [
            'name' => 'Shared',
        ]);

        $response->assertCreated();
    });
});

describe('update', function () {
    it('updates a tag name', function () {
        $tag = Tag::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Original',
        ]);

        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/tags/{$tag->id}", [
            'name' => 'Updated',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('tag.name', 'Updated');

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => 'Updated',
        ]);
    });

    it('updates a tag color', function () {
        $tag = Tag::factory()->create([
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/tags/{$tag->id}", [
            'color' => '#ef4444',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('tag.color', '#ef4444');
    });

    it('validates unique name excluding self', function () {
        $tag1 = Tag::factory()->withName('Tag One')->create([
            'workspace_id' => $this->workspace->id,
        ]);
        $tag2 = Tag::factory()->withName('Tag Two')->create([
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/tags/{$tag2->id}", [
            'name' => 'Tag One',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    it('allows updating to same name', function () {
        $tag = Tag::factory()->withName('Same Name')->create([
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/tags/{$tag->id}", [
            'name' => 'Same Name',
            'color' => '#ff0000',
        ]);

        $response->assertSuccessful();
    });

    it('returns not found for tag in different workspace', function () {
        $otherWorkspace = Workspace::factory()->create();
        $tag = Tag::factory()->create([
            'workspace_id' => $otherWorkspace->id,
        ]);

        $response = $this->putJson("/api/v1/workspaces/{$this->workspace->id}/tags/{$tag->id}", [
            'name' => 'Test',
        ]);

        $response->assertNotFound();
    });
});

describe('destroy', function () {
    it('deletes a tag', function () {
        $tag = Tag::factory()->create([
            'workspace_id' => $this->workspace->id,
        ]);

        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/tags/{$tag->id}");

        $response->assertSuccessful();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    });

    it('returns not found for tag in different workspace', function () {
        $otherWorkspace = Workspace::factory()->create();
        $tag = Tag::factory()->create([
            'workspace_id' => $otherWorkspace->id,
        ]);

        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspace->id}/tags/{$tag->id}");

        $response->assertNotFound();
    });
});

describe('permissions', function () {
    it('allows viewer to view tags', function () {
        $viewer = User::factory()->create();
        $this->workspace->members()->attach($viewer->id, [
            'role' => 'viewer',
            'joined_at' => now(),
        ]);
        Passport::actingAs($viewer);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/tags");

        $response->assertSuccessful();
    });

    it('forbids viewer from creating tags', function () {
        $viewer = User::factory()->create();
        $this->workspace->members()->attach($viewer->id, [
            'role' => 'viewer',
            'joined_at' => now(),
        ]);
        Passport::actingAs($viewer);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/tags", [
            'name' => 'Test',
        ]);

        $response->assertForbidden();
    });

    it('allows member to create tags', function () {
        $member = User::factory()->create();
        $this->workspace->members()->attach($member->id, [
            'role' => 'member',
            'joined_at' => now(),
        ]);
        Passport::actingAs($member);

        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/tags", [
            'name' => 'Member Tag',
        ]);

        $response->assertCreated();
    });
});
