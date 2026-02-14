<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => 'owner', 'joined_at' => now()]);
});

describe('Workspaces', function () {
    describe('CRUD Operations', function () {
        it('can list user workspaces', function () {
            $response = $this->actingAs($this->user, 'api')
                ->getJson('/api/v1/workspaces');

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        '*' => ['id', 'name', 'slug', 'owner'],
                    ],
                ]);
        });

        it('can create a workspace', function () {
            $response = $this->actingAs($this->user, 'api')
                ->postJson('/api/v1/workspaces', [
                    'name' => 'New Workspace',
                ]);

            $response->assertStatus(201)
                ->assertJsonPath('workspace.name', 'New Workspace');

            $this->assertDatabaseHas('workspaces', [
                'name' => 'New Workspace',
                'owner_id' => $this->user->id,
            ]);
        });

        it('can view a workspace', function () {
            $response = $this->actingAs($this->user, 'api')
                ->getJson("/api/v1/workspaces/{$this->workspace->id}");

            $response->assertStatus(200)
                ->assertJsonPath('workspace.id', $this->workspace->id);
        });

        it('can update a workspace', function () {
            $response = $this->actingAs($this->user, 'api')
                ->putJson("/api/v1/workspaces/{$this->workspace->id}", [
                    'name' => 'Updated Name',
                ]);

            $response->assertStatus(200)
                ->assertJsonPath('workspace.name', 'Updated Name');
        });

        it('can delete a workspace as owner', function () {
            $response = $this->actingAs($this->user, 'api')
                ->deleteJson("/api/v1/workspaces/{$this->workspace->id}");

            $response->assertStatus(200);

            $this->assertDatabaseMissing('workspaces', [
                'id' => $this->workspace->id,
            ]);
        });

        it('cannot access workspace as non-member', function () {
            $otherUser = User::factory()->create();

            $response = $this->actingAs($otherUser, 'api')
                ->getJson("/api/v1/workspaces/{$this->workspace->id}");

            $response->assertStatus(403);
        });
    });

    describe('Workspace Members', function () {
        it('can list workspace members', function () {
            $response = $this->actingAs($this->user, 'api')
                ->getJson("/api/v1/workspaces/{$this->workspace->id}/members");

            $response->assertStatus(200)
                ->assertJsonCount(1, 'data');
        });

        it('can update member role as owner', function () {
            $member = User::factory()->create();
            $this->workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

            $response = $this->actingAs($this->user, 'api')
                ->putJson("/api/v1/workspaces/{$this->workspace->id}/members/{$member->id}", [
                    'role' => 'admin',
                ]);

            $response->assertStatus(200);

            $this->assertDatabaseHas('workspace_members', [
                'workspace_id' => $this->workspace->id,
                'user_id' => $member->id,
                'role' => 'admin',
            ]);
        });

        it('can remove member as owner', function () {
            $member = User::factory()->create();
            $this->workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

            $response = $this->actingAs($this->user, 'api')
                ->deleteJson("/api/v1/workspaces/{$this->workspace->id}/members/{$member->id}");

            $response->assertStatus(200);

            $this->assertDatabaseMissing('workspace_members', [
                'workspace_id' => $this->workspace->id,
                'user_id' => $member->id,
            ]);
        });

        it('member cannot update roles', function () {
            $member = User::factory()->create();
            $this->workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

            $anotherMember = User::factory()->create();
            $this->workspace->members()->attach($anotherMember->id, ['role' => 'member', 'joined_at' => now()]);

            $response = $this->actingAs($member, 'api')
                ->putJson("/api/v1/workspaces/{$this->workspace->id}/members/{$anotherMember->id}", [
                    'role' => 'admin',
                ]);

            $response->assertStatus(403);
        });

        it('can leave workspace as member', function () {
            $member = User::factory()->create();
            $this->workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

            $response = $this->actingAs($member, 'api')
                ->postJson("/api/v1/workspaces/{$this->workspace->id}/leave");

            $response->assertStatus(200);

            $this->assertDatabaseMissing('workspace_members', [
                'workspace_id' => $this->workspace->id,
                'user_id' => $member->id,
            ]);
        });

        it('owner cannot leave workspace', function () {
            $response = $this->actingAs($this->user, 'api')
                ->postJson("/api/v1/workspaces/{$this->workspace->id}/leave");

            $response->assertStatus(403);
        });
    });

    describe('Workspace Invitations', function () {
        it('can create invitation as owner', function () {
            $response = $this->actingAs($this->user, 'api')
                ->postJson("/api/v1/workspaces/{$this->workspace->id}/invitations", [
                    'email' => 'newmember@example.com',
                    'role' => 'member',
                ]);

            $response->assertStatus(201);

            $this->assertDatabaseHas('invitations', [
                'workspace_id' => $this->workspace->id,
                'email' => 'newmember@example.com',
                'role' => 'member',
            ]);
        });

        it('can list invitations', function () {
            $this->workspace->invitations()->create([
                'email' => 'pending@example.com',
                'role' => 'member',
                'token' => 'test-token',
                'invited_by' => $this->user->id,
                'expires_at' => now()->addDays(7),
            ]);

            $response = $this->actingAs($this->user, 'api')
                ->getJson("/api/v1/workspaces/{$this->workspace->id}/invitations");

            $response->assertStatus(200)
                ->assertJsonCount(1, 'data');
        });

        it('can delete invitation', function () {
            $invitation = $this->workspace->invitations()->create([
                'email' => 'pending@example.com',
                'role' => 'member',
                'token' => 'test-token',
                'invited_by' => $this->user->id,
                'expires_at' => now()->addDays(7),
            ]);

            $response = $this->actingAs($this->user, 'api')
                ->deleteJson("/api/v1/workspaces/{$this->workspace->id}/invitations/{$invitation->id}");

            $response->assertStatus(200);

            $this->assertDatabaseMissing('invitations', [
                'id' => $invitation->id,
            ]);
        });

        it('cannot invite existing member', function () {
            $member = User::factory()->create();
            $this->workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

            $response = $this->actingAs($this->user, 'api')
                ->postJson("/api/v1/workspaces/{$this->workspace->id}/invitations", [
                    'email' => $member->email,
                    'role' => 'member',
                ]);

            $response->assertStatus(422);
        });
    });
});

describe('Role-Based Access Control', function () {
    it('viewer cannot create workflows', function () {
        $viewer = User::factory()->create();
        $this->workspace->members()->attach($viewer->id, ['role' => 'viewer', 'joined_at' => now()]);

        $response = $this->actingAs($viewer, 'api')
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows", [
                'name' => 'Test Workflow',
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

        $response->assertStatus(403);
    });

    it('member can create workflows', function () {
        $member = User::factory()->create();
        $this->workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        $response = $this->actingAs($member, 'api')
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/workflows", [
                'name' => 'Test Workflow',
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

        $response->assertStatus(201);
    });

    it('admin can manage members', function () {
        $admin = User::factory()->create();
        $this->workspace->members()->attach($admin->id, ['role' => 'admin', 'joined_at' => now()]);

        $member = User::factory()->create();
        $this->workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        $response = $this->actingAs($admin, 'api')
            ->putJson("/api/v1/workspaces/{$this->workspace->id}/members/{$member->id}", [
                'role' => 'viewer',
            ]);

        $response->assertStatus(200);
    });
});
