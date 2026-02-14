<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

class ResolveWorkspaceRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->route('workspace');
        $user = $request->user();

        if (! $workspace || ! $user) {
            abort(403, 'Unauthorized.');
        }

        $pivot = $workspace->members()->where('user_id', $user->id)->first()?->pivot;

        if (! $pivot) {
            abort(403, 'You are not a member of this workspace.');
        }

        $role = Role::findByName($pivot->role, 'api');

        $request->attributes->set('workspace_role', $pivot->role);
        $request->attributes->set('workspace_permissions', $role->permissions->pluck('name')->toArray());

        return $next($request);
    }
}
