<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePermissionService
{
    /**
     * Permission mapping for each role
     *
     * @var array<string, array<string>>
     */
    private const ROLE_PERMISSIONS = [
        'owner' => [
            'workspace.view',
            'workspace.update',
            'workspace.delete',
            'workspace.manage-billing',
            'member.view',
            'member.invite',
            'member.update',
            'member.remove',
            'workflow.view',
            'workflow.create',
            'workflow.update',
            'workflow.delete',
            'workflow.execute',
            'workflow.activate',
            'workflow.export',
            'workflow.import',
            'credential.view',
            'credential.create',
            'credential.update',
            'credential.delete',
            'execution.view',
            'execution.delete',
            'webhook.view',
            'webhook.create',
            'webhook.update',
            'webhook.delete',
            'variable.view',
            'variable.create',
            'variable.update',
            'variable.delete',
            'tag.view',
            'tag.create',
            'tag.update',
            'tag.delete',
            'environment.view',
            'environment.create',
            'environment.deploy',
        ],
        'admin' => [
            'workspace.view',
            'workspace.update',
            'member.view',
            'member.invite',
            'member.update',
            'member.remove',
            'workflow.view',
            'workflow.create',
            'workflow.update',
            'workflow.delete',
            'workflow.execute',
            'workflow.activate',
            'workflow.export',
            'workflow.import',
            'credential.view',
            'credential.create',
            'credential.update',
            'credential.delete',
            'execution.view',
            'execution.delete',
            'webhook.view',
            'webhook.create',
            'webhook.update',
            'webhook.delete',
            'variable.view',
            'variable.create',
            'variable.update',
            'variable.delete',
            'tag.view',
            'tag.create',
            'tag.update',
            'tag.delete',
            'environment.view',
            'environment.create',
            'environment.deploy',
        ],
        'member' => [
            'workspace.view',
            'member.view',
            'workflow.view',
            'workflow.create',
            'workflow.update',
            'workflow.execute',
            'workflow.export',
            'credential.view',
            'credential.create',
            'credential.update',
            'execution.view',
            'webhook.view',
            'webhook.create',
            'webhook.update',
            'variable.view',
            'variable.create',
            'variable.update',
            'tag.view',
            'tag.create',
            'tag.update',
            'environment.view',
        ],
        'viewer' => [
            'workspace.view',
            'member.view',
            'workflow.view',
            'credential.view',
            'execution.view',
            'webhook.view',
            'variable.view',
            'tag.view',
            'environment.view',
        ],
    ];

    /** @var array<string, string|false> */
    private array $roleCache = [];

    public function getUserRoleInWorkspace(User $user, Workspace $workspace): ?string
    {
        $cacheKey = $user->id.':'.$workspace->id;

        if (! array_key_exists($cacheKey, $this->roleCache)) {
            $member = $workspace->members()->where('user_id', $user->id)->first();
            $this->roleCache[$cacheKey] = $member?->pivot?->role ?? false;
        }

        $role = $this->roleCache[$cacheKey];

        return $role === false ? null : $role;
    }

    public function isMember(User $user, Workspace $workspace): bool
    {
        return $this->getUserRoleInWorkspace($user, $workspace) !== null;
    }

    public function isOwner(User $user, Workspace $workspace): bool
    {
        return $workspace->owner_id === $user->id;
    }

    public function hasPermission(User $user, Workspace $workspace, string $permission): bool
    {
        if ($this->isOwner($user, $workspace)) {
            $permissions = self::ROLE_PERMISSIONS['owner'] ?? [];

            return in_array($permission, $permissions, true);
        }

        $role = $this->getUserRoleInWorkspace($user, $workspace);

        if (! $role) {
            return false;
        }

        $permissions = self::ROLE_PERMISSIONS[$role] ?? [];

        return in_array($permission, $permissions, true);
    }

    /**
     * @param  array<string>  $permissions
     */
    public function hasAnyPermission(User $user, Workspace $workspace, array $permissions): bool
    {
        $role = $this->resolveEffectiveRole($user, $workspace);

        if (! $role) {
            return false;
        }

        $rolePermissions = self::ROLE_PERMISSIONS[$role] ?? [];

        foreach ($permissions as $permission) {
            if (in_array($permission, $rolePermissions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string>  $permissions
     */
    public function hasAllPermissions(User $user, Workspace $workspace, array $permissions): bool
    {
        $role = $this->resolveEffectiveRole($user, $workspace);

        if (! $role) {
            return false;
        }

        $rolePermissions = self::ROLE_PERMISSIONS[$role] ?? [];

        foreach ($permissions as $permission) {
            if (! in_array($permission, $rolePermissions, true)) {
                return false;
            }
        }

        return true;
    }

    public function authorize(User $user, Workspace $workspace, string $permission): void
    {
        if (! $this->hasPermission($user, $workspace, $permission)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }

    public function authorizeMembership(User $user, Workspace $workspace): void
    {
        if (! $this->isMember($user, $workspace)) {
            abort(403, 'You are not a member of this workspace.');
        }
    }

    /**
     * @return array<string>
     */
    public function getPermissionsForRole(string $role): array
    {
        return self::ROLE_PERMISSIONS[$role] ?? [];
    }

    /**
     * @return array<string>
     */
    public function getValidRoles(): array
    {
        return array_keys(self::ROLE_PERMISSIONS);
    }

    public function clearCache(): void
    {
        $this->roleCache = [];
    }

    private function resolveEffectiveRole(User $user, Workspace $workspace): ?string
    {
        if ($this->isOwner($user, $workspace)) {
            return 'owner';
        }

        return $this->getUserRoleInWorkspace($user, $workspace);
    }
}
