<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $roles = [
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

        // Create all unique permissions
        $allPermissions = collect($roles)->flatten()->unique();
        $permissionModels = $allPermissions->mapWithKeys(
            fn (string $name) => [$name => Permission::findOrCreate($name, 'api')]
        );

        // Create roles and assign permissions
        foreach ($roles as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'api')
                ->syncPermissions($permissionModels->only($permissions));
        }
    }
}
