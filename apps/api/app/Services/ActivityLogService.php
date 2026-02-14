<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ActivityLogService
{
    /**
     * Log an activity
     */
    public function log(
        Workspace $workspace,
        string $action,
        ?User $user = null,
        ?string $description = null,
        ?Model $subject = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null
    ): ActivityLog {
        return ActivityLog::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user?->id,
            'action' => $action,
            'description' => $description,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    /**
     * Log a resource creation
     */
    public function logCreated(
        Workspace $workspace,
        Model $subject,
        ?User $user = null,
        ?Request $request = null
    ): ActivityLog {
        $action = $this->getActionName($subject, 'created');
        $description = $this->getDescription($subject, 'created');

        return $this->log(
            workspace: $workspace,
            action: $action,
            user: $user,
            description: $description,
            subject: $subject,
            newValues: $subject->toArray(),
            request: $request
        );
    }

    /**
     * Log a resource update
     */
    public function logUpdated(
        Workspace $workspace,
        Model $subject,
        array $oldValues,
        ?User $user = null,
        ?Request $request = null
    ): ActivityLog {
        $action = $this->getActionName($subject, 'updated');
        $description = $this->getDescription($subject, 'updated');

        return $this->log(
            workspace: $workspace,
            action: $action,
            user: $user,
            description: $description,
            subject: $subject,
            oldValues: $oldValues,
            newValues: $subject->fresh()?->toArray(),
            request: $request
        );
    }

    /**
     * Log a resource deletion
     */
    public function logDeleted(
        Workspace $workspace,
        Model $subject,
        ?User $user = null,
        ?Request $request = null
    ): ActivityLog {
        $action = $this->getActionName($subject, 'deleted');
        $description = $this->getDescription($subject, 'deleted');

        return $this->log(
            workspace: $workspace,
            action: $action,
            user: $user,
            description: $description,
            subject: $subject,
            oldValues: $subject->toArray(),
            request: $request
        );
    }

    private function getActionName(Model $subject, string $verb): string
    {
        $className = class_basename($subject);
        $snakeCase = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className) ?? $className);

        return "{$snakeCase}.{$verb}";
    }

    private function getDescription(Model $subject, string $verb): string
    {
        $className = class_basename($subject);
        $name = $subject->getAttribute('name') ?? $subject->getAttribute('key') ?? "#{$subject->getKey()}";

        return ucfirst("{$className} '{$name}' was {$verb}");
    }
}
