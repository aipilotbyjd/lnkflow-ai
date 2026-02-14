<?php

namespace App\Services;

use App\Models\Workflow;
use App\Models\WorkflowEnvironmentRelease;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use App\Models\WorkspaceEnvironment;

class GitEnvironmentService
{
    public function createEnvironment(Workspace $workspace, array $data): WorkspaceEnvironment
    {
        return $workspace->environments()->create([
            'name' => $data['name'],
            'git_branch' => $data['git_branch'],
            'base_branch' => $data['base_branch'] ?? 'main',
            'is_default' => (bool) ($data['is_default'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
    }

    public function promote(
        Workflow $workflow,
        WorkspaceEnvironment $from,
        WorkspaceEnvironment $to,
        int $userId,
        ?WorkflowVersion $version = null
    ): WorkflowEnvironmentRelease {
        $version ??= $workflow->versions()->orderByDesc('version_number')->first();

        $previousRelease = WorkflowEnvironmentRelease::query()
            ->where('workflow_id', $workflow->id)
            ->where('to_environment_id', $to->id)
            ->latest()
            ->first();

        $previousVersion = $previousRelease?->workflowVersion;

        return WorkflowEnvironmentRelease::query()->create([
            'workspace_id' => $workflow->workspace_id,
            'workflow_id' => $workflow->id,
            'from_environment_id' => $from->id,
            'to_environment_id' => $to->id,
            'workflow_version_id' => $version?->id,
            'triggered_by' => $userId,
            'action' => 'promote',
            'commit_sha' => null,
            'diff_summary' => $this->diffSummary($previousVersion, $version),
        ]);
    }

    public function rollback(
        Workflow $workflow,
        WorkspaceEnvironment $to,
        int $userId,
        WorkflowVersion $targetVersion
    ): WorkflowEnvironmentRelease {
        $latest = WorkflowEnvironmentRelease::query()
            ->where('workflow_id', $workflow->id)
            ->where('to_environment_id', $to->id)
            ->latest()
            ->first();

        return WorkflowEnvironmentRelease::query()->create([
            'workspace_id' => $workflow->workspace_id,
            'workflow_id' => $workflow->id,
            'from_environment_id' => $to->id,
            'to_environment_id' => $to->id,
            'workflow_version_id' => $targetVersion->id,
            'triggered_by' => $userId,
            'action' => 'rollback',
            'commit_sha' => null,
            'diff_summary' => $this->diffSummary($latest?->workflowVersion, $targetVersion),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function diffSummary(?WorkflowVersion $from, ?WorkflowVersion $to): array
    {
        if (! $to) {
            return [
                'changed' => false,
                'summary' => 'No target version found for promotion.',
            ];
        }

        if (! $from) {
            return [
                'changed' => true,
                'summary' => 'First promotion to environment.',
                'to_version' => $to->version_number,
                'node_delta' => count($to->nodes ?? []),
                'edge_delta' => count($to->edges ?? []),
            ];
        }

        $nodeDelta = count($to->nodes ?? []) - count($from->nodes ?? []);
        $edgeDelta = count($to->edges ?? []) - count($from->edges ?? []);

        return [
            'changed' => json_encode($from->nodes) !== json_encode($to->nodes)
                || json_encode($from->edges) !== json_encode($to->edges)
                || $from->trigger_type !== $to->trigger_type,
            'from_version' => $from->version_number,
            'to_version' => $to->version_number,
            'node_delta' => $nodeDelta,
            'edge_delta' => $edgeDelta,
            'trigger_changed' => $from->trigger_type !== $to->trigger_type,
        ];
    }
}
