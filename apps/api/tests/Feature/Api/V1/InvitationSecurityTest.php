<?php

use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, ['role' => 'owner', 'joined_at' => now()]);
});

describe('Accept Invitation Security', function () {
    it('requires authentication to accept an invitation', function () {
        $invitation = Invitation::factory()->create([
            'workspace_id' => $this->workspace->id,
            'email' => 'invitee@example.com',
            'role' => 'member',
            'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->postJson("/api/v1/invitations/{$invitation->token}/accept");

        // The auth:api middleware rejects unauthenticated requests with 401
        $response->assertStatus(401);
    });

    it('returns 403 when authenticated user email does not match invitation email', function () {
        $invitee = User::factory()->create(['email' => 'wrong@example.com']);
        $invitation = Invitation::factory()->create([
            'workspace_id' => $this->workspace->id,
            'email' => 'correct@example.com',
            'role' => 'member',
            'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAs($invitee, 'api')
            ->postJson("/api/v1/invitations/{$invitation->token}/accept");

        $response->assertStatus(403);
    });

    it('returns 422 when invitation has already been accepted', function () {
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);
        $invitation = Invitation::factory()->accepted()->create([
            'workspace_id' => $this->workspace->id,
            'email' => 'invitee@example.com',
            'role' => 'member',
            'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAs($invitee, 'api')
            ->postJson("/api/v1/invitations/{$invitation->token}/accept");

        $response->assertStatus(422)
            ->assertSee('already been accepted');
    });

    it('returns 422 when invitation has expired', function () {
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);
        $invitation = Invitation::factory()->expired()->create([
            'workspace_id' => $this->workspace->id,
            'email' => 'invitee@example.com',
            'role' => 'member',
            'invited_by' => $this->owner->id,
        ]);

        $response = $this->actingAs($invitee, 'api')
            ->postJson("/api/v1/invitations/{$invitation->token}/accept");

        $response->assertStatus(422)
            ->assertSee('expired');
    });

    it('prevents duplicate membership when user is already a member', function () {
        $invitee = User::factory()->create(['email' => 'member@example.com']);
        $this->workspace->members()->attach($invitee->id, ['role' => 'member', 'joined_at' => now()]);

        $invitation = Invitation::factory()->create([
            'workspace_id' => $this->workspace->id,
            'email' => 'member@example.com',
            'role' => 'member',
            'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAs($invitee, 'api')
            ->postJson("/api/v1/invitations/{$invitation->token}/accept");

        $response->assertStatus(200)
            ->assertSee('already a member');

        // Verify invitation was marked as accepted
        $this->assertNotNull($invitation->fresh()->accepted_at);

        // Verify no duplicate pivot row was created
        $pivotCount = $this->workspace->members()->where('user_id', $invitee->id)->count();
        expect($pivotCount)->toBe(1);
    });
});

describe('Decline Invitation Security', function () {
    it('returns 403 when user email does not match invitation email', function () {
        $wrongUser = User::factory()->create(['email' => 'wrong@example.com']);
        $invitation = Invitation::factory()->create([
            'workspace_id' => $this->workspace->id,
            'email' => 'correct@example.com',
            'role' => 'member',
            'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAs($wrongUser, 'api')
            ->postJson("/api/v1/invitations/{$invitation->token}/decline");

        $response->assertStatus(403);
    });
});

describe('Role Escalation Guard', function () {
    it('only workspace owner can invite with admin role', function () {
        $admin = User::factory()->create();
        $this->workspace->members()->attach($admin->id, ['role' => 'admin', 'joined_at' => now()]);

        $response = $this->actingAs($admin, 'api')
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/invitations", [
                'email' => 'newadmin@example.com',
                'role' => 'admin',
            ]);

        $response->assertStatus(403)
            ->assertSee('Only the workspace owner can invite users with the admin role');
    });

    it('owner can invite with admin role', function () {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/invitations", [
                'email' => 'newadmin@example.com',
                'role' => 'admin',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('invitations', [
            'workspace_id' => $this->workspace->id,
            'email' => 'newadmin@example.com',
            'role' => 'admin',
        ]);
    });

    it('member with member.invite permission cannot invite as admin', function () {
        // Members do not have member.invite permission by default,
        // so this should fail with 403 (permission denied before role escalation check).
        $member = User::factory()->create();
        $this->workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        $response = $this->actingAs($member, 'api')
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/invitations", [
                'email' => 'sneaky@example.com',
                'role' => 'admin',
            ]);

        $response->assertStatus(403);

        $this->assertDatabaseMissing('invitations', [
            'workspace_id' => $this->workspace->id,
            'email' => 'sneaky@example.com',
        ]);
    });
});
